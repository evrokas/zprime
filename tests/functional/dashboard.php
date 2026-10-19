<?php
/* Websites and places: add, edit, remove, validation, per-user isolation,
 * escaping. Account A does the work; account B is the outsider. */

function zprime_db_rows(string $sql, array $args = []): array {
    $st = dbConnection::getConnection()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function zprime_functional_dashboard(TestRunner $runner, string $baseUrl): void {
    $a = new TestHttpClient($baseUrl);
    $b = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($a, TestFixtures::USER_A, TestFixtures::PASS_A);
    TestFixtures::loginAs($b, TestFixtures::USER_B, TestFixtures::PASS_B);
    $idA = TestFixtures::userId(TestFixtures::USER_A);

    $token = function (TestHttpClient $c): string { return TestFixtures::csrfFrom($c->get('/')['body']); };
    $post = function (TestHttpClient $c, string $path, array $f) use ($token) {
        return $c->post($path, $f + ['csrf_token' => $token($c)]);
    };

    $runner->add('the dashboard shows the clock, both blocks and a sign-out link', function () use ($a) {
        $html = $a->get('/')['body'];
        foreach (['data-clock', 'data-weather', 'id="h-links"', 'id="h-places"', '/logout'] as $needle) assert_contains($needle, $html);
    });

    $runner->add('adding a website stores a normalised address owned by the user', function () use ($a, $post, $idA) {
        $res = $post($a, '/links/save', ['title' => 'ZPMS', 'url' => 'pms.erns.eu']);
        assert_equal(302, $res['status']);
        $rows = zprime_db_rows('SELECT * FROM dash_links WHERE user_id = ?', [$idA]);
        assert_equal(1, count($rows));
        assert_equal('https://pms.erns.eu', $rows[0]['url']);
        assert_contains('https://pms.erns.eu', $a->get('/')['body']);
    });

    $runner->add('a website without a title takes its host name', function () use ($a, $post, $idA) {
        $post($a, '/links/save', ['title' => '', 'url' => 'https://www.github.com/evrokas']);
        $rows = zprime_db_rows("SELECT title FROM dash_links WHERE user_id = ? AND url LIKE '%github%'", [$idA]);
        assert_equal('github.com', $rows[0]['title']);
    });

    $runner->add('javascript:, data: and malformed addresses are rejected and nothing is stored', function () use ($a, $post, $idA) {
        $before = count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA]));
        foreach (['javascript:alert(1)', 'data:text/html,hi', 'not a url', ''] as $bad) {
            $post($a, '/links/save', ['title' => 'Bad', 'url' => $bad]);
        }
        assert_equal($before, count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA])));
        $page = $a->get('/')['body'];
        assert_not_contains('javascript:alert', $page);
    });

    $runner->add('a title with markup is escaped on the page', function () use ($a, $post) {
        $post($a, '/links/save', ['title' => '<script>alert(1)</script>', 'url' => 'example.org']);
        $page = $a->get('/')['body'];
        assert_not_contains('<script>alert(1)</script>', $page, 'unescaped title reached the page');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
    });

    $runner->add('editing a website changes it in place', function () use ($a, $post, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'ZPMS'", [$idA])[0]['id'];
        $post($a, '/links/save', ['id' => (string)$id, 'title' => 'ZPMS prod', 'url' => 'https://pms.erns.eu/login']);
        $row = zprime_db_rows('SELECT title, url FROM dash_links WHERE id = ?', [$id])[0];
        assert_equal('ZPMS prod', $row['title']);
        assert_equal('https://pms.erns.eu/login', $row['url']);
    });

    $runner->add('another account sees none of these websites', function () use ($b) {
        $page = $b->get('/')['body'];
        assert_not_contains('pms.erns.eu', $page);
        assert_contains('No websites yet', $page);
    });

    $runner->add('another account cannot edit or remove a website it does not own', function () use ($b, $post, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'ZPMS prod'", [$idA])[0]['id'];
        $post($b, '/links/save', ['id' => (string)$id, 'title' => 'Hijacked', 'url' => 'https://evil.example']);
        $post($b, "/links/$id/delete", []);
        $row = zprime_db_rows('SELECT title, url FROM dash_links WHERE id = ?', [$id]);
        assert_equal(1, count($row), 'another account deleted the row');
        assert_equal('ZPMS prod', $row[0]['title'], 'another account edited the row');
    });

    $runner->add('removing a website deletes only that one', function () use ($a, $post, $idA) {
        $before = count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA]));
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'ZPMS prod'", [$idA])[0]['id'];
        $post($a, "/links/$id/delete", []);
        assert_equal($before - 1, count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA])));
    });

    $runner->add('a write without a CSRF token is refused and stores nothing', function () use ($a, $idA) {
        $before = count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA]));
        $res = $a->post('/links/save', ['title' => 'NoToken', 'url' => 'example.net']);
        assert_equal(403, $res['status']);
        assert_equal($before, count(zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ?', [$idA])));
        $before = count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA]));
        assert_equal(403, $a->post('/places/save', ['name' => 'NoToken', 'where' => '1,1'])['status']);
        assert_equal($before, count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA])));
    });

    $runner->add('places: an address and coordinates both get a working Drive link', function () use ($a, $post, $idA) {
        $post($a, '/places/save', ['name' => 'Home', 'where' => 'Kifisias 12, Marousi']);
        $post($a, '/places/save', ['name' => 'Clinic', 'where' => '38.0421, 23.8105']);
        $post($a, '/places/save', ['name' => 'Hospital', 'where' => 'https://www.google.com/maps/@37.9838,23.7275,15z']);
        $page = $a->get('/')['body'];
        assert_contains('destination=Kifisias%2012%2C%20Marousi&amp;travelmode=driving', $page);
        assert_contains('destination=38.042100%2C23.810500&amp;travelmode=driving', $page);
        assert_contains('destination=37.983800%2C23.727500&amp;travelmode=driving', $page);
        assert_contains('target="_blank" rel="noopener noreferrer"', $page);
        assert_equal(3, count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA])));
    });

    $runner->add('places: invalid coordinates, unreadable links and empty names are rejected', function () use ($a, $post, $idA) {
        $before = count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA]));
        $post($a, '/places/save', ['name' => 'Bad', 'where' => '95, 10']);
        $post($a, '/places/save', ['name' => 'Bad', 'where' => 'https://maps.app.goo.gl/abc']);
        $post($a, '/places/save', ['name' => '', 'where' => '38, 23']);
        assert_equal($before, count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA])));
    });

    $runner->add('places: editing switches a place from an address to coordinates', function () use ($a, $post, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_places WHERE user_id = ? AND name = 'Home'", [$idA])[0]['id'];
        $post($a, '/places/save', ['id' => (string)$id, 'name' => 'Home', 'where' => '38.0, 23.7']);
        $row = zprime_db_rows('SELECT address, lat, lon FROM dash_places WHERE id = ?', [$id])[0];
        assert_null($row['address']);
        assert_equal('38.000000', $row['lat']);
    });

    $runner->add('places: another account sees none, and cannot edit or remove them', function () use ($b, $post, $idA) {
        assert_not_contains('Drive to Clinic', $b->get('/')['body']);
        $id = (int)zprime_db_rows("SELECT id FROM dash_places WHERE user_id = ? AND name = 'Clinic'", [$idA])[0]['id'];
        $post($b, '/places/save', ['id' => (string)$id, 'name' => 'Hijacked', 'where' => '1, 1']);
        $post($b, "/places/$id/delete", []);
        $row = zprime_db_rows('SELECT name FROM dash_places WHERE id = ?', [$id]);
        assert_equal('Clinic', $row[0]['name'] ?? null, 'another account changed or removed the place');
    });

    $runner->add('places: removing one deletes only that place', function () use ($a, $post, $idA) {
        $before = count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA]));
        $id = (int)zprime_db_rows("SELECT id FROM dash_places WHERE user_id = ? AND name = 'Clinic'", [$idA])[0]['id'];
        $post($a, "/places/$id/delete", []);
        assert_equal($before - 1, count(zprime_db_rows('SELECT id FROM dash_places WHERE user_id = ?', [$idA])));
    });

    $runner->add('the installable-app files are served', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $m = $http->get('/manifest.webmanifest');
        assert_equal(200, $m['status']);
        assert_equal('Dashboard', json_decode($m['body'], true)['name'] ?? null);
        assert_equal(200, $http->get('/assets/icon-192.png')['status']);
        assert_equal(200, $http->get('/assets/apple-touch-icon.png')['status']);
        $ico = $http->get('/favicon.ico');
        assert_equal(200, $ico['status']);
        assert_equal('image/vnd.microsoft.icon', zprime_icon_mime($ico['body']), '/favicon.ico is not an icon');
        assert_equal(200, $http->get('/assets/favicon-32.png')['status']);
        $login = $http->get('/login')['body'];
        assert_contains('rel="manifest"', $login);
        assert_contains('href="/assets/favicon-32.png"', $login, 'the page does not link the favicon');
        assert_contains('viewport-fit=cover', $login);
    });
}
