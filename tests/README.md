# ZPrime regression suite

```sh
bin/run_tests.sh                 # everything
bin/run_tests.sh --static-only   # no database
bin/run_tests.sh --functional-only
```

1. **Static checks:** `php -l` on every PHP file, `node --check` on the JS, a brace check on the CSS,
   every `.zetem` template compiled through the real ZETEM compiler and `php -l`'d, and the
   installable-app files (manifest valid, icons exist at their declared sizes).
2. **Functional tests**, over real HTTP (`php -S` + curl with a cookie jar) against a throwaway database:
   sign-in, wrong passwords, lockout, CSRF, the 30-day remember-me cookie (lifetime, flags, restoring a
   session from the cookie alone with a different User-Agent, rotation, revocation on sign-out),
   websites and places (add, edit, remove, validation, escaping, isolation between two accounts), and
   the weather path (fetch, cache, stale reading, failure back-off) against a local stand-in for Open-Meteo,
   and website icons (discovery, storage, owner-only serving, refresh, the private-address guard) against a
   local fake website (`tests/lib/icon_site.php`).

## One-time setup

1. Link the framework: `ln -s /path/to/zeusfw fw` and `ln -s ../fw/core web/core`.
2. Create a database used for nothing else. The suite **drops and recreates it on every run**, and
   refuses to start unless its name contains "test":
   ```sql
   CREATE USER 'zprime_test'@'localhost' IDENTIFIED BY '...';
   CREATE DATABASE zprime_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON zprime_test_db.* TO 'zprime_test'@'localhost';
   ```
3. `cp config/db.test.php.in config/db.test.php` and fill in the credentials.

## Safety

- Nothing under `tests/` reads `config/db.php`, except as plain text to confirm the test database is a
  different one.
- The test web server's router (`tests/lib/router.php`) defines the test database itself when started
  with `ZPRIME_TEST_SERVER=1`, and `web/index.php` skips `config/db.php` once the settings exist, so the
  test server never sees real credentials. (`auto_prepend_file` was tried first and is not applied
  to the router script under `php -S` in every PHP build.)
- `DROP DATABASE` is guarded by a second check on the name and by a marker table every write test
  confirms before it changes anything.
