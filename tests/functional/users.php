<?php
/* Adding people: through /admin/users (the owner's screen) and through bin/setup.php. */

function zprime_functional_users(TestRunner $runner, string $baseUrl): void {
    $owner = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($owner, TestFixtures::USER_OWNER, TestFixtures::PASS_OWNER);
    $plain = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($plain, TestFixtures::USER_A, TestFixtures::PASS_A);
    $token = function (TestHttpClient $c, string $path = '/'): string { return TestFixtures::csrfFrom($c->get($path)['body']); };

    $runner->add('the owner sees a Users link; an ordinary account does not', function () use ($owner, $plain) {
        assert_contains('href="/admin/users"', $owner->get('/')['body']);
        assert_not_contains('/admin/users', $plain->get('/')['body']);
    });

    $runner->add('an ordinary account cannot open the user screens', function () use ($plain) {
        foreach (['/admin/users', '/admin/users/new', '/admin/roles'] as $path) {
            $body = $plain->get($path)['body'];
            assert_not_contains('Add new', $body, "$path opened for an ordinary account");
            assert_contains('401', $body, "$path did not show the unauthorized page");
        }
    });

    $runner->add('the owner can open the list and the new-user form', function () use ($owner) {
        $list = $owner->get('/admin/users');
        assert_equal(200, $list['status']);
        assert_contains('Add new', $list['body']);
        $form = $owner->get('/admin/users/new')['body'];
        foreach (['name="name"', 'name="email"', 'name="uname"', 'name="password"', 'name="active"'] as $field) {
            assert_contains($field, $form);
        }
    });

    $runner->add('the dashboard blocks appear on the home page only, not under the admin screens', function () use ($owner) {
        assert_contains('id="h-links"', $owner->get('/')['body']);
        $admin = $owner->get('/admin/users')['body'];
        assert_not_contains('id="h-links"', $admin);
        assert_not_contains('id="h-places"', $admin);
    });

    $runner->add('a password is required and nothing is stored without it', function () use ($owner, $token) {
        $owner->post('/admin/users/new', ['csrf_token' => $token($owner), 'name' => 'No Pass', 'email' => 'np@example.invalid', 'uname' => 'zprime_np', 'active' => '1']);
        assert_equal(0, count(zprime_db_rows("SELECT id FROM users WHERE uname = 'zprime_np'")));
    });

    $runner->add('a user added on the web form can sign in straight away, as an ordinary user', function () use ($owner, $token, $baseUrl) {
        $res = $owner->post('/admin/users/new', [
            'csrf_token' => $token($owner), 'name' => 'Maria Test', 'email' => 'maria@example.invalid',
            'uname' => 'zprime_maria', 'password' => 'maria-password-1', 'active' => '1',
        ]);
        assert_equal(302, $res['status']);
        $row = zprime_db_rows("SELECT active, expired, roles, upass FROM users WHERE uname = 'zprime_maria'");
        assert_equal(1, count($row), 'the account was not created');
        assert_equal('1', (string)$row[0]['active']);
        assert_true(password_verify('maria-password-1', $row[0]['upass']), 'the password was not stored as a hash');
        assert_equal(0, count(zprime_db_rows("SELECT ur.id FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.uname = 'zprime_maria'")), 'a role was assigned behind the owner\'s back');

        $maria = new TestHttpClient($baseUrl);
        $login = TestFixtures::login($maria, 'zprime_maria', 'maria-password-1');
        assert_equal(302, $login['status'], 'sign-in did not redirect');
        $html = $maria->get('/')['body'];
        assert_contains('data-clock', $html, 'the dashboard header is missing: the sign-in was only half done');
        assert_not_contains('initialized falsely', $html);
        assert_not_contains('Role is not valid', $html);
        assert_not_contains('href="/admin/users"', $html, 'an ordinary user was shown the Users link');
        assert_contains('Maria Test', $html);
    });

    $runner->add('the new user has their own empty lists and cannot use the admin screens', function () use ($baseUrl) {
        $maria = new TestHttpClient($baseUrl);
        TestFixtures::loginAs($maria, 'zprime_maria', 'maria-password-1');
        $html = $maria->get('/')['body'];
        assert_contains('No websites yet', $html);
        assert_contains('No places yet', $html);
        $body = $maria->get('/admin/users')['body'];
        assert_not_contains('Add new', $body);
        $res = $maria->post('/admin/users/new', ['csrf_token' => TestFixtures::csrfFrom($html), 'name' => 'X', 'email' => 'x@example.invalid', 'uname' => 'zprime_x', 'password' => 'x-password-123', 'active' => '1']);
        assert_equal(0, count(zprime_db_rows("SELECT id FROM users WHERE uname = 'zprime_x'")), 'an ordinary user created an account');
    });

    $runner->add('an account left inactive on the form cannot sign in', function () use ($owner, $token, $baseUrl) {
        $owner->post('/admin/users/new', [
            'csrf_token' => $token($owner), 'name' => 'Inactive', 'email' => 'i@example.invalid',
            'uname' => 'zprime_inactive', 'password' => 'inactive-password-1',
        ]);
        assert_equal('0', (string)zprime_db_rows("SELECT active FROM users WHERE uname = 'zprime_inactive'")[0]['active']);
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_inactive', 'inactive-password-1');
        assert_not_contains('data-clock', $c->get('/')['body'], 'an inactive account got into the dashboard');
    });

    // ----- switching accounts off / deleting them -----
    $editUser = function (TestHttpClient $o, string $uname, array $fields) use ($token): array {
        $id = (int)zprime_db_rows('SELECT id FROM users WHERE uname = ?', [$uname])[0]['id'];
        $u = zprime_db_rows('SELECT name, email FROM users WHERE id = ?', [$id])[0];
        return $o->post("/admin/users/$id/edit", array_merge(
            ['csrf_token' => $token($o, "/admin/users/$id/edit"), 'name' => $u['name'], 'email' => $u['email'], 'uname' => $uname], $fields));
    };
    $signInRemembered = function (string $uname, string $pass) use ($baseUrl): array {
        $c = new TestHttpClient($baseUrl);
        $res = TestFixtures::login($c, $uname, $pass, true);
        preg_match('/^set-cookie:\s*zeusfwrememberme=([^;]*)/mi', $res['headers'], $m);
        return [$c, $m[1] ?? ''];
    };

    $runner->add('switching an account off signs out its open session at once and ends its 30-day cookie', function () use ($owner, $editUser, $signInRemembered, $baseUrl) {
        [$session, $cookie] = $signInRemembered('zprime_maria', 'maria-password-1');
        assert_not_equal('', $cookie, 'no remember-me cookie was issued');
        assert_contains('data-clock', $session->get('/')['body']);
        assert_equal(1, (int)zprime_db_rows("SELECT COUNT(*) AS c FROM user_tokens WHERE uname = 'zprime_maria'")[0]['c'] >= 1 ? 1 : 0);

        $editUser($owner, 'zprime_maria', []);                                 // Active not ticked -> off
        assert_equal('0', (string)zprime_db_rows("SELECT active FROM users WHERE uname = 'zprime_maria'")[0]['active']);

        $res = $session->get('/');
        assert_not_contains('data-clock', $res['body'], 'the open session survived');
        assert_equal(0, (int)zprime_db_rows("SELECT COUNT(*) AS c FROM user_tokens WHERE uname = 'zprime_maria'")[0]['c'], 'remember-me tokens were kept');

        $fresh = new TestHttpClient($baseUrl);                                 // another device holding the cookie
        $res = $fresh->get('/', ['Cookie' => 'zeusfwrememberme=' . $cookie]);
        assert_contains('/login', (string)$res['location'], 'the 30-day cookie still worked');
    });

    $runner->add('a switched-off account cannot sign in with its password; switching it back on restores that', function () use ($owner, $editUser, $baseUrl) {
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_maria', 'maria-password-1');
        assert_not_contains('data-clock', $c->get('/')['body'], 'an off account signed in');

        $editUser($owner, 'zprime_maria', ['active' => '1']);
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_maria', 'maria-password-1');
        assert_contains('data-clock', $c->get('/')['body'], 'the account does not work after being switched back on');
    });

    $runner->add('an expired account is treated like an inactive one', function () use ($owner, $editUser, $signInRemembered) {
        [$session] = $signInRemembered('zprime_maria', 'maria-password-1');
        assert_contains('data-clock', $session->get('/')['body']);
        $editUser($owner, 'zprime_maria', ['active' => '1', 'expired' => '1']);
        assert_not_contains('data-clock', $session->get('/')['body'], 'an expired account kept its session');
        $editUser($owner, 'zprime_maria', ['active' => '1']);
    });

    $runner->add('deleting an account ends its open session', function () use ($owner, $token, $baseUrl) {
        $owner->post('/admin/users/new', ['csrf_token' => $token($owner), 'name' => 'Temp', 'email' => 't@example.invalid',
            'uname' => 'zprime_temp', 'password' => 'temp-password-123', 'active' => '1']);
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_temp', 'temp-password-123');
        assert_contains('data-clock', $c->get('/')['body']);
        $id = (int)zprime_db_rows("SELECT id FROM users WHERE uname = 'zprime_temp'")[0]['id'];
        $owner->post("/admin/users/$id/delete", ['csrf_token' => $token($owner)]);
        assert_equal(0, count(zprime_db_rows('SELECT id FROM users WHERE id = ?', [$id])), 'the account was not deleted');
        assert_not_contains('data-clock', $c->get('/')['body'], 'a deleted account kept its session');
    });

    $runner->add('an ordinary user who stays active is never signed out by the check', function () use ($baseUrl) {
        $c = new TestHttpClient($baseUrl);
        TestFixtures::loginAs($c, TestFixtures::USER_A, TestFixtures::PASS_A);
        for ($i = 0; $i < 3; $i++) assert_contains('data-clock', $c->get('/')['body']);
    });

    // ----- bin/setup.php -----
    $setup = function (array $args, string $password = 'cli-password-1234') {
        $cmd = 'cd ' . escapeshellarg(ZPRIME_TEST_APPDIR) . ' && ZPRIME_DB_CONFIG=' . escapeshellarg(ZPRIME_TEST_APPDIR . '/config/db.test.php')
             . ' ZPRIME_PASSWORD=' . escapeshellarg($password) . ' php bin/setup.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        return ['code' => $code, 'out' => implode("\n", $out)];
    };
    $isOwner = fn(string $u) => (int)zprime_db_rows("SELECT COUNT(*) AS c FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN users x ON x.id = ur.user_id WHERE x.uname = ? AND r.is_superuser = 1", [$u])[0]['c'] > 0;

    $runner->add('setup.php adds an ordinary user when an owner already exists', function () use ($setup, $isOwner, $baseUrl) {
        $r = $setup(['--user=zprime_cli_plain', '--name=Cli Plain']);
        assert_equal(0, $r['code'], $r['out']);
        assert_contains('ordinary user', $r['out']);
        assert_false($isOwner('zprime_cli_plain'), 'a later account was made an owner');
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_cli_plain', 'cli-password-1234');
        assert_contains('data-clock', $c->get('/')['body'], 'the account made by setup.php cannot sign in');
    });

    $runner->add('setup.php --owner makes an owner, and re-running resets a password without changing roles', function () use ($setup, $isOwner, $baseUrl) {
        $r = $setup(['--user=zprime_cli_owner', '--owner']);
        assert_equal(0, $r['code'], $r['out']);
        assert_true($isOwner('zprime_cli_owner'));

        $r = $setup(['--user=zprime_cli_plain'], 'a-new-password-5678');
        assert_equal(0, $r['code'], $r['out']);
        assert_false($isOwner('zprime_cli_plain'), 'a password reset made the account an owner');
        $c = new TestHttpClient($baseUrl);
        TestFixtures::login($c, 'zprime_cli_plain', 'a-new-password-5678');
        assert_contains('data-clock', $c->get('/')['body'], 'the new password does not work');
        $old = new TestHttpClient($baseUrl);
        TestFixtures::login($old, 'zprime_cli_plain', 'cli-password-1234');
        assert_not_contains('data-clock', $old->get('/')['body'], 'the old password still works');
    });

    $runner->add('setup.php refuses short passwords and bad user names', function () use ($setup) {
        $r = $setup(['--user=zprime_short'], 'short');
        assert_not_equal(0, $r['code']);
        assert_equal(0, count(zprime_db_rows("SELECT id FROM users WHERE uname = 'zprime_short'")));
        $r = $setup(['--user=bad name!']);
        assert_not_equal(0, $r['code']);
    });
}
