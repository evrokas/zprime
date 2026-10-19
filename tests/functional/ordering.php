<?php
/* Reordering websites and places: one step at a time, only inside the
 * owner's own list, robust against duplicate sort_order values. */

function zprime_functional_ordering(TestRunner $runner, string $baseUrl): void {
    // Own accounts' lists start empty here: earlier suites left rows behind, so use a clean user.
    $a = new TestHttpClient($baseUrl);
    $b = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($a, TestFixtures::USER_A, TestFixtures::PASS_A);
    TestFixtures::loginAs($b, TestFixtures::USER_B, TestFixtures::PASS_B);
    $idA = TestFixtures::userId(TestFixtures::USER_A);
    $idB = TestFixtures::userId(TestFixtures::USER_B);
    $db = dbConnection::getConnection();
    $db->exec("DELETE FROM dash_links WHERE user_id IN ($idA, $idB)");
    $db->exec("DELETE FROM dash_places WHERE user_id IN ($idA, $idB)");

    $token = function (TestHttpClient $c): string { return TestFixtures::csrfFrom($c->get('/')['body']); };
    $move = function (TestHttpClient $c, string $kind, int $id, string $dir, bool $withToken = true) use ($token): array {
        $headers = $withToken ? ['X-CSRF-Token' => $token($c)] : [];
        $res = $c->postWithHeaders("/$kind/$id/move", ['dir' => $dir], $headers);
        $res['json'] = json_decode($res['body'], true);
        return $res;
    };
    $order = function (string $table, int $uid): array {
        return array_column(zprime_db_rows("SELECT title_or_name FROM (SELECT " . ($table === 'dash_links' ? 'title' : 'name')
            . " AS title_or_name, sort_order, id FROM $table WHERE user_id = ?) t ORDER BY sort_order, id", [$uid]), 'title_or_name');
    };
    $ids = function (string $table, int $uid, string $col): array {
        $rows = zprime_db_rows("SELECT id, $col AS label FROM $table WHERE user_id = ?", [$uid]);
        return array_column($rows, 'id', 'label');
    };
    $shownOrder = function (TestHttpClient $c, string $cssClass) use ($token): array {
        $html = $c->get('/')['body'];
        preg_match_all('#<li class="' . $cssClass . '"[^>]*data-item-id="(\d+)"#', $html, $m);
        return array_map('intval', $m[1]);
    };

    $links = ['One', 'Two', 'Three', 'Four'];
    foreach ($links as $t) {
        $a->post('/links/save', ['title' => $t, 'url' => "https://$t.example.net", 'csrf_token' => $token($a)]);
    }
    $places = ['Home', 'Clinic', 'Hospital'];
    foreach ($places as $t) {
        $a->post('/places/save', ['name' => $t, 'where' => '38.0, 23.7', 'csrf_token' => $token($a)]);
    }

    $runner->add('new items go to the end, and the page lists them in that order', function () use ($order, $idA, $ids, $shownOrder, $a) {
        assert_equal(['One', 'Two', 'Three', 'Four'], $order('dash_links', $idA));
        $byTitle = $ids('dash_links', $idA, 'title');
        assert_equal(array_map(fn($t) => (int)$byTitle[$t], ['One', 'Two', 'Three', 'Four']), $shownOrder($a, 'site-item'));
    });

    $runner->add('the first item has "earlier" disabled and the last has "later" disabled', function () use ($a) {
        $html = $a->get('/')['body'];
        preg_match_all('#<li class="site-item".*?</li>#s', $html, $m);
        $cards = $m[0];
        assert_equal(4, count($cards));
        assert_true((bool)preg_match('/data-move="up"\s+disabled/', $cards[0]), 'first item can move earlier');
        assert_false((bool)preg_match('/data-move="down"\s+disabled/', $cards[0]));
        assert_true((bool)preg_match('/data-move="down"\s+disabled/', $cards[3]), 'last item can move later');
        assert_false((bool)preg_match('/data-move="up"\s+disabled/', $cards[3]));
        assert_false((bool)preg_match('/data-move="\w+"\s+disabled/', $cards[1]), 'a middle item has a disabled move button');
    });

    $runner->add('moving a website later and earlier swaps it with its neighbour', function () use ($move, $a, $ids, $order, $idA) {
        $id = (int)$ids('dash_links', $idA, 'title')['Two'];
        $res = $move($a, 'links', $id, 'down');
        assert_equal(200, $res['status']);
        assert_true($res['json']['ok']);
        assert_equal(['One', 'Three', 'Two', 'Four'], $order('dash_links', $idA));
        $move($a, 'links', $id, 'up');
        $move($a, 'links', $id, 'up');
        assert_equal(['Two', 'One', 'Three', 'Four'], $order('dash_links', $idA));
    });

    $runner->add('the page shows the new order after a reload', function () use ($a, $ids, $idA, $shownOrder) {
        $byTitle = $ids('dash_links', $idA, 'title');
        assert_equal(array_map(fn($t) => (int)$byTitle[$t], ['Two', 'One', 'Three', 'Four']), $shownOrder($a, 'site-item'));
    });

    $runner->add('moving past either end changes nothing and is not an error', function () use ($move, $a, $ids, $order, $idA) {
        $byTitle = $ids('dash_links', $idA, 'title');
        $res = $move($a, 'links', (int)$byTitle['Two'], 'up');          // already first
        assert_equal(200, $res['status']);
        $res = $move($a, 'links', (int)$byTitle['Four'], 'down');       // already last
        assert_equal(200, $res['status']);
        assert_equal(['Two', 'One', 'Three', 'Four'], $order('dash_links', $idA));
    });

    $runner->add('places move the same way', function () use ($move, $a, $ids, $order, $idA, $shownOrder) {
        $byName = $ids('dash_places', $idA, 'name');
        assert_true($move($a, 'places', (int)$byName['Hospital'], 'up')['json']['ok']);
        assert_equal(['Home', 'Hospital', 'Clinic'], $order('dash_places', $idA));
        assert_true($move($a, 'places', (int)$byName['Home'], 'down')['json']['ok']);
        assert_equal(['Hospital', 'Home', 'Clinic'], $order('dash_places', $idA));
        assert_equal(array_map(fn($n) => (int)$byName[$n], ['Hospital', 'Home', 'Clinic']), $shownOrder($a, 'place'));
    });

    $runner->add('rows that share the same sort_order are still moved correctly', function () use ($move, $a, $ids, $order, $idA, $db) {
        $db->exec("UPDATE dash_links SET sort_order = 0 WHERE user_id = $idA");        // all equal: order falls back to id
        $byTitle = $ids('dash_links', $idA, 'title');
        $id = (int)$byTitle['Three'];
        $expectedBefore = array_column(zprime_db_rows('SELECT title FROM dash_links WHERE user_id = ? ORDER BY id', [$idA]), 'title');
        assert_equal($expectedBefore, $order('dash_links', $idA));
        assert_true($move($a, 'links', $id, 'up')['json']['ok']);
        $after = $order('dash_links', $idA);
        $i = array_search('Three', $after, true);
        assert_equal(array_search('Three', $expectedBefore, true) - 1, $i, 'the item did not move exactly one step');
        $positions = array_column(zprime_db_rows('SELECT sort_order FROM dash_links WHERE user_id = ? ORDER BY sort_order', [$idA]), 'sort_order');
        assert_equal(['0', '1', '2', '3'], array_map('strval', $positions), 'the list was not renumbered 0..n-1');
    });

    $runner->add('another account cannot move my items, and its own list is untouched', function () use ($move, $b, $ids, $order, $idA, $idB) {
        $a_links = $ids('dash_links', $idA, 'title');
        $before = $order('dash_links', $idA);
        $res = $move($b, 'links', (int)$a_links['One'], 'down');
        assert_equal(404, $res['status']);
        assert_false($res['json']['ok']);
        $res = $move($b, 'places', (int)$ids('dash_places', $idA, 'name')['Home'], 'up');
        assert_equal(404, $res['status']);
        assert_equal($before, $order('dash_links', $idA), 'another account changed my order');
    });

    $runner->add('another account moving its own items does not disturb mine', function () use ($b, $move, $token, $order, $idA, $idB) {
        foreach (['B1', 'B2'] as $t) $b->post('/links/save', ['title' => $t, 'url' => "https://$t.example.net", 'csrf_token' => $token($b)]);
        $mine = $order('dash_links', $idA);
        $bid = (int)zprime_db_rows("SELECT id FROM dash_links WHERE user_id = ? AND title = 'B2'", [$idB])[0]['id'];
        assert_true($move($b, 'links', $bid, 'up')['json']['ok']);
        assert_equal(['B2', 'B1'], $order('dash_links', $idB));
        assert_equal($mine, $order('dash_links', $idA));
    });

    $runner->add('a move needs the CSRF token, a valid direction and a signed-in user', function () use ($move, $a, $ids, $idA, $order, $baseUrl) {
        $id = (int)$ids('dash_links', $idA, 'title')['One'];
        $before = $order('dash_links', $idA);
        assert_equal(403, $move($a, 'links', $id, 'up', false)['status'], 'moved without a CSRF token');
        assert_equal(400, $move($a, 'links', $id, 'sideways')['status']);
        assert_equal(400, $move($a, 'links', $id, '')['status']);
        $anon = new TestHttpClient($baseUrl);
        assert_contains('/login', (string)$anon->post("/links/$id/move", ['dir' => 'up'])['location']);
        assert_contains('/login', (string)$anon->post("/places/$id/move", ['dir' => 'up'])['location']);
        assert_equal($before, $order('dash_links', $idA));
    });

    $runner->add('moving a deleted or unknown item answers 404', function () use ($move, $a) {
        $res = $move($a, 'links', 99999999, 'up');
        assert_equal(404, $res['status']);
        assert_false($res['json']['ok']);
    });

    $runner->add('the edit mode shows move buttons wired to the right list', function () use ($a) {
        $html = $a->get('/')['body'];
        assert_contains('class="sites" data-move-base="/links"', $html);
        assert_contains('class="places" data-move-base="/places"', $html);
        assert_contains('data-move-status', $html);
        assert_true((bool)preg_match('/aria-label="Move One earlier"/', $html));
        assert_true((bool)preg_match('/aria-label="Move Hospital up"/', $html));
    });
}
