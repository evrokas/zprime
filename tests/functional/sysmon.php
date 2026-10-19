<?php
/* The System block: host config validation (pure), then the JSON endpoints against a stub agent. */

function zprime_functional_sysmon_helpers(TestRunner $runner): void {
    $runner->add('hosts: only complete, safe entries survive, ids keep their position', function () {
        $h = zprime_sysmon_hosts(['sysmonitor_path' => '/opt/sysmonitor/', 'hosts' => [
            ['name' => 'ok https', 'url' => 'https://a.example/api.php', 'token' => 't'],
            ['name' => 'plain http, not allowed', 'url' => 'http://a.example/api.php', 'token' => 't'],
            ['name' => 'plain http, allowed', 'url' => 'http://10.0.0.5/api.php', 'token' => 't', 'allow_http' => true],
            ['name' => 'no token', 'url' => 'https://a.example/api.php'],
            ['name' => '', 'url' => 'https://a.example/api.php', 'token' => 't'],
            ['name' => 'javascript', 'url' => 'javascript:alert(1)', 'token' => 't'],
            ['name' => 'file', 'url' => 'file:///etc/passwd', 'token' => 't', 'allow_http' => true],
            ['name' => 'This server', 'local' => true],
        ]]);
        assert_equal([0, 2, 7], array_keys($h));
        assert_true($h[7]['local']);
        assert_equal('/opt/sysmonitor', $h[7]['path']);
        assert_true(!$h[0]['local']);
    });
    $runner->add('hosts: a local host without sysmonitor_path, and junk config, are ignored', function () {
        assert_equal([], zprime_sysmon_hosts(['hosts' => [['name' => 'x', 'local' => true]]]));
        assert_equal([], zprime_sysmon_hosts(['hosts' => 'nonsense']));
        assert_equal([], zprime_sysmon_hosts([]));
    });
}

function zprime_functional_sysmon(TestRunner $runner, string $baseUrl, $stub, ?string $localNote): void {
    $owner = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($owner, TestFixtures::USER_OWNER, TestFixtures::PASS_OWNER);
    $user = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($user, TestFixtures::USER_A, TestFixtures::PASS_A);
    $anon = new TestHttpClient($baseUrl);
    $get = fn($c, $p) => json_decode($c->get($p)['body'], true);

    $ageCaches = function (int $s) {
        foreach (glob(zprime_sysmon_cache_dir() . '/zprime-sysmon-*.json') ?: [] as $f) {
            $d = json_decode(file_get_contents($f), true);
            $d['fetched'] -= $s;
            file_put_contents($f, json_encode($d));
        }
    };

    $runner->add('the owner gets every host: the stub is online with items and sparklines, bad ones offline', function () use ($owner, $get, $stub) {
        $res = $owner->get('/api/sysmon');
        assert_equal(200, $res['status']);
        assert_false(str_contains($res['body'], 'tok-sys-test'), 'the agent token leaked to the browser');
        $j = json_decode($res['body'], true);
        $h = $j['hosts'];
        assert_equal('ok', $h[0]['state']);
        assert_equal('Stub box', $h[0]['name']);
        assert_equal(42.5, $h[0]['items'][0]['value']);
        assert_true(count($h[0]['spark']['cpu.load']) > 10, 'no cpu sparkline');
        assert_true(isset($h[0]['spark']['mem.used_pct']));
        assert_equal('offline', $h[1]['state']);       // nothing listens there
        assert_equal('offline', $h[2]['state']);       // wrong token: 401 from the agent
        assert_equal([], $h[1]['items']);
    });

    $runner->add('another signed-in account and an anonymous visitor are refused', function () use ($user, $anon) {
        assert_equal(403, $user->get('/api/sysmon')['status']);
        assert_equal(403, $user->get('/api/sysmon/0/series?metric=cpu.load&range=1h')['status']);
        $r = $anon->get('/api/sysmon');
        assert_true(in_array($r['status'], [301, 302, 303, 401, 403], true), 'anonymous got ' . $r['status']);
        assert_false(str_contains($r['body'], 'Stub box'));
    });

    $runner->add('the dashboard shows the block to the owner only', function () use ($owner, $user) {
        $page = $owner->get('/')['body'];
        assert_contains('data-sysmon', $page);
        assert_contains('id="sys-dialog"', $page);
        assert_false(str_contains($page, 'tok-sys-test'));
        assert_false(str_contains($page, '127.0.0.1:8784'), 'agent address in the page');
        assert_false(str_contains($user->get('/')['body'], 'data-sysmon'));
    });

    $runner->add('a second request inside the cache window does not ask the agent again', function () use ($owner, $stub) {
        $owner->get('/api/sysmon');
        $before = $stub->requests();
        $owner->get('/api/sysmon');
        $owner->get('/api/sysmon');
        assert_equal($before, $stub->requests(), 'cache not used');
    });

    $runner->add('history: one metric over a range, every instance', function () use ($owner) {
        $res = $owner->get('/api/sysmon/0/series?metric=disk.used_pct&range=7d');
        assert_equal(200, $res['status']);
        $j = json_decode($res['body'], true);
        assert_equal(2, count($j['series']));
        assert_equal('/data', $j['series'][1]['instance']);
        assert_equal(['ts', 'avg', 'min', 'max', 'n'], $j['columns']);
        assert_false(str_contains($res['body'], 'tok-sys-test'));
    });

    $runner->add('history: unknown host, bad range or metric are rejected without calling the agent', function () use ($owner, $stub) {
        $before = $stub->requests();
        assert_equal(404, $owner->get('/api/sysmon/9/series?metric=cpu.load&range=1h')['status']);
        assert_equal(503, $owner->get('/api/sysmon/0/series?metric=cpu.load&range=forever')['status']);
        assert_equal(503, $owner->get('/api/sysmon/0/series?metric=' . rawurlencode("cpu.load' OR 1=1") . '&range=1h')['status']);
        assert_equal(503, $owner->get('/api/sysmon/0/series?range=1h')['status']);
        assert_equal($before, $stub->requests(), 'invalid requests reached the agent');
    });

    $runner->add('when the agent goes down the last data is served, marked stale, and it is not hammered', function () use ($owner, $stub, $ageCaches) {
        $ageCaches(3600);
        $stub->setMode('fail');
        $h = json_decode($owner->get('/api/sysmon')['body'], true)['hosts'][0];
        assert_equal('stale', $h['state']);
        assert_equal(42.5, $h['items'][0]['value']);
        $before = $stub->requests();
        for ($i = 0; $i < 4; $i++) $owner->get('/api/sysmon');
        assert_equal($before, $stub->requests(), 'retried a failing agent on every request');
    });

    $runner->add('with no data at all and the agent down, the host shows offline; it recovers afterwards', function () use ($owner, $stub) {
        foreach (glob(zprime_sysmon_cache_dir() . '/zprime-sysmon-*') ?: [] as $f) @unlink($f);
        $h = json_decode($owner->get('/api/sysmon')['body'], true)['hosts'][0];
        assert_equal('offline', $h['state']);
        $stub->setMode('ok');
        foreach (glob(zprime_sysmon_cache_dir() . '/zprime-sysmon-*.fail') ?: [] as $f) @unlink($f);
        assert_equal('ok', json_decode($owner->get('/api/sysmon')['body'], true)['hosts'][0]['state']);
    });

    if ($localNote === null) {
        $runner->add('a local host is read in-process from the sysmonitor database', function () use ($owner) {
            $h = json_decode($owner->get('/api/sysmon')['body'], true)['hosts'];
            assert_equal('This server', $h[3]['name']);
            assert_true($h[3]['local']);
            assert_equal('silent', $h[3]['state'], 'old fixture data should read as a silent collector');
            assert_true(abs($h[3]['items'][0]['value'] - 77) < 0.01);
            $s = json_decode($owner->get('/api/sysmon/3/series?metric=cpu.load&range=90d')['body'], true);
            assert_equal('total', $s['series'][0]['instance']);
        });
    }
}
