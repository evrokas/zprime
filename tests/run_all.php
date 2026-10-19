<?php
/*
 * Entry point for the ZPrime regression suite. Run via bin/run_tests.sh --
 * see tests/README.md for setup and usage.
 *
 * Usage: php tests/run_all.php [--static-only|--functional-only]
 */

require_once __DIR__ . '/bootstrap.php';

$mode = 'all';
if (in_array('--static-only', $argv, true)) $mode = 'static';
if (in_array('--functional-only', $argv, true)) $mode = 'functional';

$ok = true;

if ($mode !== 'functional') {
    echo "=== Static consistency checks (PHP / JS / CSS / templates / app manifest) ===\n\n";

    require_once __DIR__ . '/static/php_lint.php';
    require_once __DIR__ . '/static/js_lint.php';
    require_once __DIR__ . '/static/css_consistency.php';
    require_once __DIR__ . '/static/template_consistency.php';
    require_once __DIR__ . '/static/pwa_consistency.php';

    foreach ([
        ['PHP syntax', 'zprime_static_php_lint'],
        ['JS syntax', 'zprime_static_js_lint'],
        ['CSS consistency', 'zprime_static_css_consistency'],
        ['Template consistency', 'zprime_static_template_consistency'],
        ['Installable app files', 'zprime_static_pwa_consistency'],
    ] as [$title, $fn]) {
        $r = new TestRunner($title);
        $fn($r);
        $ok = $r->run() && $ok;
    }
}

if ($mode === 'static') {
    exit($ok ? 0 : 1);
}

if (!$ok && $mode === 'all') {
    fwrite(STDERR, "\nStatic checks failed -- skipping functional tests (fix the above first).\n");
    exit(1);
}

echo "=== Functional regression tests ===\n\n";

require_once __DIR__ . '/lib/TestFixtures.php';
require_once ZPRIME_TEST_APPDIR . '/web/dash_lib.php';
require_once __DIR__ . '/functional/helpers.php';
require_once __DIR__ . '/functional/auth.php';
require_once __DIR__ . '/functional/dashboard.php';
require_once __DIR__ . '/functional/weather.php';
require_once __DIR__ . '/functional/icons.php';
require_once __DIR__ . '/functional/ordering.php';
require_once __DIR__ . '/functional/update.php';

// Pure helper checks need no database or server.
$libRunner = new TestRunner('Helpers (validation, Maps URLs)');
zprime_functional_helpers($libRunner);
$ok = $libRunner->run() && $ok;

// Fresh database + two accounts, every run.
TestSchema::reset();
TestFixtures::createUsers();
TestFixtures::createOwner();

// A stand-in for Open-Meteo, so the weather path is tested without the
// internet. The app under test reads its address from this variable.
$stub = new WeatherStub();
$stub->start();
putenv('ZPRIME_WEATHER_URL=' . $stub->url());
@unlink(__DIR__ . '/../web/cache/zprime-weather.json');
@unlink(__DIR__ . '/../web/cache/zprime-weather.json.fail');

// A fake website (pages and icons in several shapes) for the favicon tests.
$site = new IconSite();
$site->start();

// A throwaway git remote and checkout for the Update button. The main test server has the button switched
// on and pointed at that checkout; a second server (the same app, default settings) proves it is off by default.
$git = new GitSandbox();
$server = new TestServer('127.0.0.1', 8781, ['ZPRIME_GIT_PULL_BUTTON' => '1', 'ZPRIME_UPDATE_DIR' => $git->server]);
$server->start();
$serverOff = new TestServer('127.0.0.1', 8786, ['ZPRIME_UPDATE_DIR' => $git->server]);
$serverOff->start();

try {
    $authRunner = new TestRunner('Login, remember-me and CSRF');
    zprime_functional_auth($authRunner, $server->baseUrl());
    $ok = $authRunner->run() && $ok;

    $dashRunner = new TestRunner('Websites and places');
    zprime_functional_dashboard($dashRunner, $server->baseUrl());
    $ok = $dashRunner->run() && $ok;

    $wxRunner = new TestRunner('Weather');
    zprime_functional_weather($wxRunner, $server->baseUrl(), $stub);
    $ok = $wxRunner->run() && $ok;

    $iconRunner = new TestRunner('Website icons');
    zprime_functional_icons($iconRunner, $server->baseUrl(), $site);
    $ok = $iconRunner->run() && $ok;

    $orderRunner = new TestRunner('Ordering websites and places');
    zprime_functional_ordering($orderRunner, $server->baseUrl());
    $ok = $orderRunner->run() && $ok;

    $gitRunner = new TestRunner('Update button: git pull');
    zprime_functional_git_pull($gitRunner, $git);
    $ok = $gitRunner->run() && $ok;

    $updRunner = new TestRunner('Update button over HTTP');
    zprime_functional_update_http($updRunner, $server->baseUrl(), $serverOff->baseUrl(), $git);
    $ok = $updRunner->run() && $ok;
} finally {
    $serverOff->stop();
    $git->destroy();
    $server->stop();
    $stub->stop();
    $site->stop();
}

echo $ok ? "ALL TESTS PASSED\n" : "TESTS FAILED -- do not deploy this change.\n";
exit($ok ? 0 : 1);
