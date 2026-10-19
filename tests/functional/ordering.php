<?php
/* Ordering websites and places by drag and drop: the page sends the whole new
 * order; the server saves it inside the owner's own list only. */

function zprime_functional_ordering(TestRunner $runner, string $baseUrl): void {
    // Earlier suites left rows behind, so start these two accounts from empty lists.
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
    $setOrder = function (TestHttpClient $c, string $kind, string $ids, bool $withToken = true) use ($token): array {
        $headers = $withToken ? ['X-CSRF-Token' => $token($c)] : [];
        $res = $c->postWithHeaders("/$kind/order", ['ids' => $ids], $headers);
        $res['json'] = json_decode($res['body'], true);
        return $res;
    };
    /** Titles/names in saved order. */
    $order = function (string $table, int $uid): array {
        $col = $table === 'dash_links' ? 'title' : 'name';
        return array_column(zprime_db_rows("SELECT $col AS label FROM $table WHERE user_id = ? ORDER BY sort_order, id", [$uid]), 'label');
    };
    /** label => id */
    $ids = function (string $table, int $uid) {
        $col = $table === 'dash_links' ? 'title' : 'name';
        return array_column(zprime_db_rows("SELECT id, $col AS label FROM $table WHERE user_id = ?", [$uid]), 'id', 'label');
    };
    $list = function (array $by, array $labels): string { return implode(',', array_map(fn($l) => $by[$l], $labels)); };
    /** Item ids in the order the page lists them. */
    $shownOrder = function (TestHttpClient $c, string $cssClass): array {
        $html = $c->get('/')['body'];
        preg_match_all('#<li class="' . $cssClass . '"[^>]*data-item-id="(\d+)"#', $html, $m);
        return array_map('intval', $m[1]);
    };

    foreach (['One', 'Two', 'Three', 'Four'] as $t) {
        $a->post('/links/save', ['title' => $t, 'url' => "https://$t.example.net", 'csrf_token' => $token($a)]);
    }
    foreach (['Home', 'Clinic', 'Hospital'] as $t) {
        $a->post('/places/save', ['name' => $t, 'where' => '38.0, 23.7', 'csrf_token' => $token($a)]);
    }

    $runner->add('new items go to the end, and the page lists them in that order', function () use ($order, $idA, $ids, $shownOrder, $a) {
        assert_equal(['One', 'Two', 'Three', 'Four'], $order('dash_links', $idA));
        $by = $ids('dash_links', $idA);
        assert_equal(array_map(fn($t) => (int)$by[$t], ['One', 'Two', 'Three', 'Four']), $shownOrder($a, 'site-item'));
    });

    $runner->add('drag and drop: the posted order becomes the saved order, and the page shows it', function () use ($setOrder, $a, $ids, $list, $order, $idA, $shownOrder) {
        $by = $ids('dash_links', $idA);
        $want = ['Four', 'Two', 'One', 'Three'];
        $res = $setOrder($a, 'links', $list($by, $want));
        assert_equal(200, $res['status']);
        assert_true($res['json']['ok']);
        assert_equal($want, $order('dash_links', $idA));
        assert_equal(array_map(fn($t) => (int)$by[$t], $want), $shownOrder($a, 'site-item'));
    });

    $runner->add('drag and drop works for places too', function () use ($setOrder, $a, $ids, $list, $order, $idA, $shownOrder) {
        $by = $ids('dash_places', $idA);
        $want = ['Clinic', 'Hospital', 'Home'];
        assert_true($setOrder($a, 'places', $list($by, $want))['json']['ok']);
        assert_equal($want, $order('dash_places', $idA));
        assert_equal(array_map(fn($n) => (int)$by[$n], $want), $shownOrder($a, 'place'));
    });

    $runner->add('the saved positions are renumbered 0..n-1', function () use ($idA) {
        $pos = array_column(zprime_db_rows('SELECT sort_order FROM dash_links WHERE user_id = ? ORDER BY sort_order', [$idA]), 'sort_order');
        assert_equal(['0', '1', '2', '3'], array_map('strval', $pos));
    });

    $runner->add('rows that share the same sort_order are still ordered correctly', function () use ($setOrder, $a, $ids, $list, $order, $idA, $db) {
        $db->exec("UPDATE dash_links SET sort_order = 0 WHERE user_id = $idA");        // all equal: the old order falls back to id
        $by = $ids('dash_links', $idA);
        $want = ['Three', 'One', 'Four', 'Two'];
        assert_true($setOrder($a, 'links', $list($by, $want))['json']['ok']);
        assert_equal($want, $order('dash_links', $idA));
        $pos = array_column(zprime_db_rows('SELECT sort_order FROM dash_links WHERE user_id = ? ORDER BY sort_order', [$idA]), 'sort_order');
        assert_equal(['0', '1', '2', '3'], array_map('strval', $pos), 'the list was not renumbered');
    });

    $runner->add('a partial list puts those first and keeps the others in their old order after them', function () use ($setOrder, $a, $ids, $list, $order, $idA) {
        // before: Three, One, Four, Two
        $by = $ids('dash_links', $idA);
        $setOrder($a, 'links', $list($by, ['Two', 'Four']));
        assert_equal(['Two', 'Four', 'Three', 'One'], $order('dash_links', $idA));
    });

    $runner->add('foreign, unknown and repeated ids are ignored, and nothing is lost', function () use ($setOrder, $a, $ids, $order, $idA, $idB, $b, $token) {
        $b->post('/links/save', ['title' => 'B1', 'url' => 'https://b1.example.net', 'csrf_token' => $token($b)]);
        $bId = (int)zprime_db_rows('SELECT id FROM dash_links WHERE user_id = ? LIMIT 1', [$idB])[0]['id'];
        $bOrderBefore = $order('dash_links', $idB);
        $by = $ids('dash_links', $idA);

        $res = $setOrder($a, 'links', implode(',', [$bId, 99999999, $by['One'], $by['One'], $by['Two']]));
        assert_equal(200, $res['status']);
        // listed ids first (once each), then the rest in their previous order: Two, Four, Three, One -> Four, Three
        assert_equal(['One', 'Two', 'Four', 'Three'], $order('dash_links', $idA));
        assert_equal($bOrderBefore, $order('dash_links', $idB), "another account's list was touched");
        assert_equal(1, count(zprime_db_rows('SELECT id FROM dash_links WHERE id = ?', [$bId])), "another account's item vanished");
    });

    $runner->add('another account cannot reorder mine by posting my ids', function () use ($setOrder, $b, $ids, $order, $idA) {
        $by = $ids('dash_links', $idA);
        $before = $order('dash_links', $idA);
        $res = $setOrder($b, 'links', implode(',', array_reverse(array_values($by))));
        assert_equal(200, $res['status']);                       // accepted but ignored: they own none of those ids
        assert_equal($before, $order('dash_links', $idA));
        $res = $setOrder($b, 'places', implode(',', array_reverse(array_values($ids('dash_places', $idA)))));
        assert_equal(200, $res['status']);
        assert_equal(['Clinic', 'Hospital', 'Home'], $order('dash_places', $idA));
    });

    $runner->add("another account reordering its own list does not disturb mine", function () use ($setOrder, $b, $token, $ids, $order, $idA, $idB) {
        $b->post('/links/save', ['title' => 'B2', 'url' => 'https://b2.example.net', 'csrf_token' => $token($b)]);
        $mine = $order('dash_links', $idA);
        $by = $ids('dash_links', $idB);
        assert_true($setOrder($b, 'links', $by['B2'] . ',' . $by['B1'])['json']['ok']);
        assert_equal(['B2', 'B1'], $order('dash_links', $idB));
        assert_equal($mine, $order('dash_links', $idA));
    });

    $runner->add('the order endpoint needs the token, a sign-in and a well-formed list', function () use ($setOrder, $a, $baseUrl, $ids, $idA, $order) {
        $before = $order('dash_links', $idA);
        $list = implode(',', array_values($ids('dash_links', $idA)));
        assert_equal(403, $setOrder($a, 'links', $list, false)['status'], 'reordered without a CSRF token');
        foreach (['', 'abc', '1,,2', '1;2', '-1,2', '1,2,x', str_repeat('1,', 3000) . '1'] as $bad) {
            assert_equal(400, $setOrder($a, 'links', $bad)['status'], "accepted: $bad");
        }
        $anon = new TestHttpClient($baseUrl);
        assert_contains('/login', (string)$anon->post('/links/order', ['ids' => $list])['location']);
        assert_contains('/login', (string)$anon->post('/places/order', ['ids' => $list])['location']);
        assert_equal($before, $order('dash_links', $idA));
    });

    $runner->add('the page has a drag handle on every item and no up/down buttons', function () use ($a) {
        $html = $a->get('/')['body'];
        $items = substr_count($html, 'class="site-item"') + substr_count($html, 'class="place"');
        assert_true($items >= 7, 'expected the test items on the page');
        assert_equal($items, substr_count($html, 'data-drag-handle'), 'an item has no drag handle');
        assert_not_contains('data-move="', $html);
        assert_contains('class="sites" data-move-base="/links"', $html);
        assert_contains('class="places" data-move-base="/places"', $html);
        assert_contains('data-move-status', $html);
    });

    $runner->add('the one-step move routes no longer exist', function () {
        $yaml = (string)file_get_contents(ZPRIME_TEST_APPDIR . '/config/settings.info.yaml');
        assert_not_contains('/move', $yaml);
        assert_not_contains('zprime_link_move', file_get_contents(ZPRIME_TEST_APPDIR . '/web/dash.php'));
        assert_not_contains('data-move="', file_get_contents(ZPRIME_TEST_APPDIR . '/web/js/dashboard.js'));
    });

    $runner->add('the website list is a single column', function () {
        $css = (string)file_get_contents(ZPRIME_TEST_APPDIR . '/web/css/dashboard.css');
        assert_true((bool)preg_match('/\.sites\s*\{[^}]*flex-direction:\s*column/', $css), 'the websites list is not a single column');
        assert_false((bool)preg_match('/\.sites\s*\{[^}]*grid-template-columns/', $css), 'the websites list is still a grid');
    });
}
