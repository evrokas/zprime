<?php
/* Website icons: found on the (fake) site, stored, served only to their
 * owner, refreshed on request, and never lost to a failed refresh. */

function zprime_functional_icons(TestRunner $runner, string $baseUrl, IconSite $site): void {
    $a = new TestHttpClient($baseUrl);
    $b = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($a, TestFixtures::USER_A, TestFixtures::PASS_A);
    TestFixtures::loginAs($b, TestFixtures::USER_B, TestFixtures::PASS_B);
    $idA = TestFixtures::userId(TestFixtures::USER_A);

    $token = function (TestHttpClient $c): string { return TestFixtures::csrfFrom($c->get('/')['body']); };
    $addLink = function (string $title, string $url) use ($a, $token, $idA): int {
        $a->post('/links/save', ['title' => $title, 'url' => $url, 'csrf_token' => $token($a)]);
        $rows = zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ? AND title = ?', [$idA, $title]);
        assert_equal(1, count($rows), "link $title was not stored");
        return (int)$rows[0]['id'];
    };
    $refresh = function (TestHttpClient $c, int $id, bool $withToken = true) use ($token): array {
        $headers = $withToken ? ['X-CSRF-Token' => $token($c)] : [];
        $res = $c->postWithHeaders("/links/$id/icon/refresh", [], $headers);
        $res['json'] = json_decode($res['body'], true);
        return $res;
    };
    $iconWidth = function (int $id) use ($idA): ?int {
        $row = zprime_db_rows('SELECT icon FROM dash_links WHERE id = ? AND user_id = ?', [$id, $idA])[0] ?? null;
        if (!$row || $row['icon'] === null) return null;
        return getimagesizefromstring($row['icon'])[0] ?? null;
    };

    $runner->add('a new website has no icon yet and the page asks for it once', function () use ($addLink, $a, $site) {
        $id = $addLink('IconA', $site->url('/a/'));
        $html = $a->get('/')['body'];
        assert_true((bool)preg_match('/data-link-id="' . $id . '"[^>]*data-needs-icon="1"/', $html), 'the new link is not marked as needing an icon');
        assert_equal(404, $a->get("/links/$id/icon")['status'], 'an icon exists before any fetch');
    });

    $runner->add('refresh picks the declared icon closest to 64 px and skips SVG', function () use ($refresh, $a, $iconWidth, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];
        $res = $refresh($a, $id);
        assert_equal(200, $res['status']);
        assert_true($res['json']['ok'], 'refresh failed: ' . json_encode($res['json']));
        assert_contains("/links/$id/icon?v=", $res['json']['icon']);
        assert_equal(32, $iconWidth($id), 'did not choose the 32px icon');
    });

    $runner->add('the stored icon is served as an image with safe headers, and the page shows it', function () use ($a, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];
        $res = $a->get("/links/$id/icon");
        assert_equal(200, $res['status']);
        assert_true((bool)preg_match('/^content-type:\s*image\/png/mi', $res['headers']), 'wrong content type');
        assert_true((bool)preg_match('/^x-content-type-options:\s*nosniff/mi', $res['headers']), 'no nosniff');
        assert_true((bool)preg_match("/^content-security-policy:\s*default-src 'none'/mi", $res['headers']), 'no CSP on the icon');
        assert_true((bool)preg_match('/^cache-control:.*immutable/mi', $res['headers']), 'not cacheable');
        assert_equal(32, getimagesizefromstring($res['body'])[0]);

        $page = $a->get('/')['body'];
        assert_contains("/links/$id/icon?v=", $page);
        assert_not_contains('data-needs-icon="1"', explode('data-link-id="' . $id . '"', $page)[1] ? substr(explode('data-link-id="' . $id . '"', $page)[1], 0, 40) : '', 'still marked as needing an icon');
    });

    $runner->add('the page listing never carries icon bytes', function () use ($a) {
        $page = $a->get('/')['body'];
        assert_not_contains("\x89PNG", $page);
        assert_true(strlen($page) < 60000, 'the page is suspiciously large: ' . strlen($page));
    });

    $runner->add('another account cannot read, refresh or guess the icon', function () use ($b, $refresh, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];
        assert_equal(404, $b->get("/links/$id/icon")['status'], 'another account read the icon');
        $res = $refresh($b, $id);
        assert_equal(404, $res['status'], 'another account refreshed a link it does not own');
        assert_false($res['json']['ok']);
    });

    $runner->add('signed out, icons and refresh are refused', function () use ($baseUrl, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];
        $anon = new TestHttpClient($baseUrl);
        assert_contains('/login', (string)$anon->get("/links/$id/icon")['location']);
        assert_contains('/login', (string)$anon->post("/links/$id/icon/refresh", [])['location']);
    });

    $runner->add('refresh without the CSRF token is refused', function () use ($refresh, $a, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];
        $res = $refresh($a, $id, false);
        assert_equal(403, $res['status']);
        assert_false($res['json']['ok']);
    });

    $runner->add('relative, data: and /favicon.ico icons are all found', function () use ($addLink, $refresh, $a, $iconWidth, $site) {
        $cases = [
            ['IconRel', $site->url('/rel/dir/page.html'), 16],
            ['IconData', $site->url('/data/'), 20],
            ['IconFallback', $site->url('/nolinks/'), 16],          // /favicon.ico, an ICO file
            ['IconRedirect', $site->url('/redirect/'), 32],         // 302 to /a/
        ];
        foreach ($cases as [$title, $url, $width]) {
            $id = $addLink($title, $url);
            $res = $refresh($a, $id);
            assert_true($res['json']['ok'] ?? false, "$title: " . json_encode($res['json']));
            assert_equal($width, $iconWidth($id), "$title: wrong icon width");
        }
        $ico = zprime_db_rows("SELECT icon_mime FROM dash_links WHERE title = 'IconFallback'")[0]['icon_mime'];
        assert_equal('image/vnd.microsoft.icon', $ico);
    });

    $runner->add('an oversize icon is skipped in favour of the next candidate', function () use ($addLink, $refresh, $a, $iconWidth, $site) {
        $id = $addLink('IconHuge', $site->url('/oversize/'));
        $res = $refresh($a, $id);
        assert_true($res['json']['ok'] ?? false, json_encode($res['json']));
        assert_equal(16, $iconWidth($id), 'the oversize file was stored');
        assert_equal('image/vnd.microsoft.icon', zprime_db_rows('SELECT icon_mime FROM dash_links WHERE id = ?', [$id])[0]['icon_mime']);
    });

    $runner->add('SVG-only sites, soft-404 favicons, missing pages and dead hosts report a clear failure', function () use ($addLink, $refresh, $a, $site, $idA) {
        $cases = [
            ['NoIconSvg', $site->url('/svgonly/', 'localhost'), 'No icon was found'],
            ['NoIconMissing', $site->url('/missing/', 'localhost'), 'No icon was found'],
            ['NoIconLoop', $site->url('/redirect-loop/', 'localhost'), 'could not be reached'],
            ['NoIconDead', 'http://127.0.0.1:9/', 'could not be reached'],
        ];
        $failedIds = [];
        foreach ($cases as [$title, $url, $message]) {
            $id = $addLink($title, $url);
            $failedIds[] = $id;
            $res = $refresh($a, $id);
            assert_equal(200, $res['status']);
            assert_false($res['json']['ok'], "$title unexpectedly found an icon");
            assert_contains($message, $res['json']['error'], "$title: " . $res['json']['error']);
            assert_equal(404, $a->get("/links/$id/icon")['status']);
            // the attempt is recorded, so the page does not ask again on every visit
            $tried = zprime_db_rows('SELECT icon_fetched FROM dash_links WHERE id = ?', [$id])[0]['icon_fetched'];
            assert_not_null($tried, "$title: the failed attempt was not recorded");
        }
        $page = $a->get('/')['body'];
        foreach ($failedIds as $id) {
            assert_false((bool)preg_match('/data-link-id="' . $id . '"[^>]*data-needs-icon/', $page), "link $id is still asked for on every visit");
        }
    });

    $runner->add('a failed refresh keeps the icon the link already had', function () use ($addLink, $refresh, $a, $iconWidth, $site) {
        $id = $addLink('IconToggle', $site->url('/toggle/', 'localhost'));
        $site->setMode('icon');
        assert_true($refresh($a, $id)['json']['ok'], 'first refresh failed');
        assert_equal(32, $iconWidth($id));

        $site->setMode('none');
        $res = $refresh($a, $id);
        assert_false($res['json']['ok'], 'the refresh should fail now');
        assert_equal(32, $iconWidth($id), 'a failed refresh threw the old icon away');
        assert_equal(200, $a->get("/links/$id/icon")['status']);
        $site->setMode('icon');
    });

    $runner->add('editing only the title keeps the icon; moving to another site clears it', function () use ($a, $token, $refresh, $iconWidth, $site, $idA) {
        $id = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'IconA'", [$idA])[0]['id'];

        $a->post('/links/save', ['id' => (string)$id, 'title' => 'IconA renamed', 'url' => $site->url('/a/'), 'csrf_token' => $token($a)]);
        assert_equal(32, $iconWidth($id), 'a rename lost the icon');

        $a->post('/links/save', ['id' => (string)$id, 'title' => 'IconA renamed', 'url' => $site->url('/a/', 'localhost'), 'csrf_token' => $token($a)]);
        // same hostname label is what is compared: 127.0.0.1 -> localhost is another site
        assert_null($iconWidth($id), 'the icon of the old site was kept');
        $row = zprime_db_rows('SELECT icon_fetched FROM dash_links WHERE id = ?', [$id])[0];
        assert_null($row['icon_fetched'], 'the link was not queued for a new fetch');
    });

    $runner->add('the edit dialog has a refresh button wired to this link', function () use ($a) {
        $page = $a->get('/')['body'];
        assert_contains('data-refresh-icon', $page);
        assert_contains('Refresh icon', $page);
        assert_contains('data-icon-base="/links"', $page);
        assert_contains('data-icon-row', $page);
    });
}
