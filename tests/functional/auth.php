<?php
/* Sign-in, the 30-day remember-me cookie, sign-out, lockout and CSRF. Every
 * test makes its own TestHttpClient so no test leans on another's session. */

/** Pulls "name=value" of a Set-Cookie header out of a raw response header block. */
function zprime_set_cookie(array $res, string $name): ?array {
    if (!preg_match('/^set-cookie:\s*' . preg_quote($name, '/') . '=([^;]*)([^\r\n]*)/mi', $res['headers'], $m)) return null;
    return ['value' => $m[1], 'attrs' => $m[2]];
}

function zprime_functional_auth(TestRunner $runner, string $baseUrl): void {
    $runner->add('signed out, the dashboard sends you to /login', function () use ($baseUrl) {
        $res = (new TestHttpClient($baseUrl))->get('/');
        assert_equal(302, $res['status']);
        assert_contains('/login', (string)$res['location']);
    });

    $runner->add('signed out, the weather endpoint and every write route are refused', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        assert_contains('/login', (string)$http->get('/api/weather')['location'], '/api/weather open to anonymous visitors');
        foreach (['/links/save', '/places/save', '/links/1/delete', '/places/1/delete'] as $path) {
            $res = $http->post($path, ['title' => 'x']);
            assert_contains('/login', (string)$res['location'], "$path accepted a signed-out POST");
        }
    });

    $runner->add('the login page renders a CSRF field, a remember-me box and no dashboard', function () use ($baseUrl) {
        $res = (new TestHttpClient($baseUrl))->get('/login');
        assert_equal(200, $res['status']);
        assert_not_null(TestHttpClient::extractCsrfToken($res['body']));
        assert_contains('name="rememberme"', $res['body']);
        assert_not_contains('data-clock', $res['body'], 'dashboard markup leaked onto the login page');
    });

    $runner->add('a wrong password is refused and gives no session', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $res = TestFixtures::login($http, TestFixtures::USER_A, 'wrong-password-xyz');
        assert_contains('/login', (string)$res['location']);
        assert_contains('/login', (string)$http->get('/')['location'], 'still signed in after a wrong password');
    });

    $runner->add('an unknown user gets the same answer as a wrong password', function () use ($baseUrl) {
        $a = TestFixtures::login(new TestHttpClient($baseUrl), 'no_such_user', 'wrong-password-xyz');
        $b = TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_A, 'wrong-password-xyz');
        assert_equal($b['status'], $a['status']);
        assert_equal($b['location'], $a['location']);
    });

    $runner->add('login without "keep me signed in" sets no persistent cookie', function () use ($baseUrl) {
        $res = TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_A, TestFixtures::PASS_A, false);
        assert_equal(302, $res['status']);
        assert_null(zprime_set_cookie($res, 'zeusfwrememberme'), 'a remember-me cookie was set without being asked for');
    });

    $runner->add('"keep me signed in" sets a 30-day, HttpOnly, SameSite=Lax cookie', function () use ($baseUrl) {
        $res = TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_A, TestFixtures::PASS_A, true);
        $c = zprime_set_cookie($res, 'zeusfwrememberme');
        assert_not_null($c, 'no remember-me cookie');
        // PHP computes Max-Age from the clock when the header is sent, so it can read a second or two under 30 days.
        assert_true((bool)preg_match('/Max-Age=(\d+)/', $c['attrs'], $m) && $m[1] <= 2592000 && $m[1] >= 2592000 - 10,
            'cookie lifetime is not 30 days (2592000 s): ' . $c['attrs']);
        assert_contains('HttpOnly', $c['attrs']);
        assert_contains('SameSite=Lax', $c['attrs']);
    });

    $runner->add('the 30-day cookie alone restores the sign-in, even with a different browser', function () use ($baseUrl) {
        $login = TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_A, TestFixtures::PASS_A, true);
        $cookie = zprime_set_cookie($login, 'zeusfwrememberme')['value'];

        // A brand-new client: no session cookie at all, a different User-Agent.
        $fresh = new TestHttpClient($baseUrl);
        $res = $fresh->get('/', ['Cookie' => 'zeusfwrememberme=' . $cookie, 'User-Agent' => 'SomeOtherPhone/9.9']);
        assert_equal(200, $res['status'], 'the remember-me cookie did not restore the session');
        assert_contains('data-clock', $res['body']);

        $rotated = zprime_set_cookie($res, 'zeusfwrememberme');
        assert_not_null($rotated, 'the cookie was not renewed on use');
        assert_not_equal($cookie, $rotated['value'], 'the validator did not rotate');
    });

    $runner->add('a tampered remember-me cookie does not sign anyone in', function () use ($baseUrl) {
        $login = TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_A, TestFixtures::PASS_A, true);
        $cookie = urldecode(zprime_set_cookie($login, 'zeusfwrememberme')['value']);
        [$selector, $validator] = explode(':', $cookie, 2);
        $bad = urlencode($selector . ':' . strrev($validator));
        $res = (new TestHttpClient($baseUrl))->get('/', ['Cookie' => 'zeusfwrememberme=' . $bad]);
        assert_contains('/login', (string)$res['location']);
    });

    $runner->add('signing out revokes this device\'s remember-me cookie', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $login = TestFixtures::login($http, TestFixtures::USER_A, TestFixtures::PASS_A, true);
        $cookie = zprime_set_cookie($login, 'zeusfwrememberme')['value'];
        $http->get('/logout');
        $res = (new TestHttpClient($baseUrl))->get('/', ['Cookie' => 'zeusfwrememberme=' . $cookie]);
        assert_contains('/login', (string)$res['location'], 'the cookie still worked after sign-out');
    });

    $runner->add('five wrong passwords lock the account, even for the right password', function () use ($baseUrl) {
        for ($i = 0; $i < 6; $i++) TestFixtures::login(new TestHttpClient($baseUrl), TestFixtures::USER_C, 'wrong-password-xyz');
        $http = new TestHttpClient($baseUrl);
        TestFixtures::login($http, TestFixtures::USER_C, TestFixtures::PASS_C);
        assert_contains('/login', (string)$http->get('/')['location'], 'a locked account could still sign in');
    });

    $runner->add('a login POST without a CSRF token is refused', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $http->get('/login');
        $res = $http->post('/login', ['username' => TestFixtures::USER_A, 'password' => TestFixtures::PASS_A]);
        assert_contains('/login', (string)$res['location']);
        assert_contains('/login', (string)$http->get('/')['location'], 'signed in without a CSRF token');
    });
}
