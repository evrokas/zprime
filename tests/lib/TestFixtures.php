<?php
/*
 * Test accounts, created straight in the (freshly rebuilt) test database.
 * Two ordinary users so the suites can prove one cannot see or change the
 * other's websites and places.
 */
class TestFixtures {
    const USER_A = 'zprime_test_a';
    const PASS_A = 'test-password-aaaa-1';
    const USER_B = 'zprime_test_b';
    const PASS_B = 'test-password-bbbb-2';
    // Only ever used to trip the lockout, so it cannot disturb the others.
    const USER_C = 'zprime_test_c';
    const PASS_C = 'test-password-cccc-3';

    static function createUsers(): void {
        TestSchema::assertSafeToMutate();
        foreach ([[self::USER_A, self::PASS_A], [self::USER_B, self::PASS_B], [self::USER_C, self::PASS_C]] as [$uname, $pass]) {
            $u = new usersClass([
                'name' => "ZPrime $uname",
                'email' => "$uname@example.invalid",
                'uname' => $uname,
                'upass' => password_hash($pass, PASSWORD_DEFAULT),
                'active' => 1,
                'expired' => 0,
                'wrongpasscount' => 0,
                'roles' => 'authenticated',
            ]);
            $u->insert();
        }
    }

    // An owner account: its role has is_superuser, which is what the Update button requires.
    const USER_OWNER = 'zprime_test_owner';
    const PASS_OWNER = 'test-password-owner-4';

    static function createOwner(): void {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();
        $db->exec("INSERT INTO roles (guid, cuser, name, label, is_superuser) VALUES (UUID(), 'test', 'owner', 'Owner', 1)");
        $roleId = (int)$db->lastInsertId();
        $u = new usersClass([
            'name' => 'ZPrime owner', 'email' => 'owner@example.invalid', 'uname' => self::USER_OWNER,
            'upass' => password_hash(self::PASS_OWNER, PASSWORD_DEFAULT),
            'active' => 1, 'expired' => 0, 'wrongpasscount' => 0, 'roles' => 'owner',
        ]);
        $u->insert();
        user_rolesClassEx::assignRole((int)$u->getid(), $roleId, 'test');
    }

    static function userId(string $uname): int {
        $st = dbConnection::getConnection()->prepare('SELECT id FROM users WHERE uname = :u');
        $st->execute([':u' => $uname]);
        return (int)$st->fetchColumn();
    }

    /** Signs in through the real /login form, scraping the CSRF token like a browser. Returns the login response. */
    static function login(TestHttpClient $http, string $username, string $password, bool $remember = false): array {
        $page = $http->get('/login');
        assert_equal(200, $page['status'], 'GET /login did not return 200');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        assert_not_null($token, 'no csrf_token field on the login page');

        $fields = ['csrf_token' => $token, 'username' => $username, 'password' => $password];
        if ($remember) $fields['rememberme'] = 'on';
        return $http->post('/login', $fields);
    }

    static function loginAs(TestHttpClient $http, string $username, string $password): void {
        $res = self::login($http, $username, $password);
        assert_equal(302, $res['status'], "login as $username did not redirect");
        $home = $http->get('/');
        assert_equal(200, $home['status'], "dashboard did not load after login as $username");
    }

    /** A fresh CSRF token read from the signed-in dashboard (the <meta name="csrf-token"> tag). */
    static function csrfFrom(string $html): string {
        assert_true((bool)preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m), 'no csrf-token meta on the page');
        return $m[1];
    }
}
