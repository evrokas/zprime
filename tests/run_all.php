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
require_once ZPRIME_TEST_APPDIR . '/web/sysmon_lib.php';
require_once __DIR__ . '/functional/helpers.php';
require_once __DIR__ . '/functional/auth.php';
require_once __DIR__ . '/functional/dashboard.php';
require_once __DIR__ . '/functional/weather.php';
require_once __DIR__ . '/functional/icons.php';
require_once __DIR__ . '/functional/ordering.php';
require_once __DIR__ . '/functional/users.php';
require_once __DIR__ . '/functional/sysmon.php';

// Pure helper checks need no database or server.
$libRunner = new TestRunner('Helpers (validation, Maps URLs)');
zprime_functional_helpers($libRunner);
zprime_functional_sysmon_helpers($libRunner);
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

// A fake sysmonitor agent for the System block, plus (when a sysmonitor checkout is next to this one,
// or ZPRIME_SYSMONITOR_SRC points at its src/) a real local database read in-process.
$sysStub = new SysmonStub();
$sysStub->start();
$localNote = null;
$sysSrc = getenv('ZPRIME_SYSMONITOR_SRC') ?: dirname(ZPRIME_TEST_APPDIR) . '/sysmonitor/src';
$sysHosts = [
    ['name' => 'Stub box', 'url' => 'http://127.0.0.1:8784/api.php', 'token' => 'tok-sys-test', 'allow_http' => true],
    ['name' => 'Dead box', 'url' => 'http://127.0.0.1:1/api.php', 'token' => 'tok-sys-test', 'allow_http' => true],
    ['name' => 'Wrong token', 'url' => 'http://127.0.0.1:8784/api.php', 'token' => 'nope', 'allow_http' => true],
];
$sysCfg = ['cache_seconds' => 5];
if (is_file($sysSrc . '/bootstrap.php')) {
    $sysDir = sys_get_temp_dir() . '/zprime_sysmonitor_' . getmypid();
    @mkdir($sysDir, 0700, true);
    @symlink($sysSrc, $sysDir . '/src');
    file_put_contents($sysDir . '/config.php', "<?php return ['db_path' => '$sysDir/s.sqlite', 'host_name' => 'local-test'];");
    require_once $sysSrc . '/bootstrap.php';
    $st = \Sysmon\Store::open($sysDir . '/s.sqlite');
    $st->insert(time() - 7200, [['cpu.load', 'total', 77.0], ['uptime.s', '', 1000.0]]);
    $st->rollup(time());
    unset($st);
    $sysHosts[] = ['name' => 'This server', 'local' => true];
    $sysCfg['sysmonitor_path'] = $sysDir;
} else {
    $localNote = 'no sysmonitor checkout found';
}
$sysCfg['hosts'] = $sysHosts;
putenv('ZPRIME_SYSMON_CONFIG_JSON=' . json_encode($sysCfg));
foreach (glob(sys_get_temp_dir() . '/zprime-sysmon-*/zprime-sysmon-*') ?: [] as $f) @unlink($f);

$server = new TestServer();
$server->start();

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

    $usersRunner = new TestRunner('Adding users');
    zprime_functional_users($usersRunner, $server->baseUrl());
    $ok = $usersRunner->run() && $ok;

    $sysRunner = new TestRunner('System block' . ($localNote ? " (local host skipped: $localNote)" : ''));
    zprime_functional_sysmon($sysRunner, $server->baseUrl(), $sysStub, $localNote);
    $ok = $sysRunner->run() && $ok;
} finally {
    $sysStub->stop();
    $server->stop();
    $stub->stop();
    $site->stop();
}

echo $ok ? "ALL TESTS PASSED\n" : "TESTS FAILED -- do not deploy this change.\n";
exit($ok ? 0 : 1);
