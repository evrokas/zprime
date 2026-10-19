<?php
/* The forecast block: pure shaping (no server), then the JSON endpoint against the weather stub. */

function zprime_functional_forecast_helpers(TestRunner $runner): void {
    $raw = function (int $offset) {
        $h = ['time' => [], 'temperature_2m' => [], 'weather_code' => [], 'precipitation_probability' => []];
        $start = gmmktime(0, 0, 0, 10, 19, 2026);                       // 2026-10-19 00:00 local
        for ($i = 0; $i < 72; $i++) {
            $h['time'][] = gmdate('Y-m-d\TH:00', $start + $i * 3600);
            $h['temperature_2m'][] = $i === 5 ? null : 10 + $i;          // one missing value
            $h['weather_code'][] = $i < 24 ? 0 : 61;
            $h['precipitation_probability'][] = $i;
        }
        $d = ['time' => ['2026-10-19', '2026-10-20', 'bad'], 'weather_code' => [0, 61, 3], 'temperature_2m_max' => [20.44, 18, null],
              'temperature_2m_min' => [11.6, 9, 1], 'precipitation_probability_max' => [5, 80, 0], 'precipitation_sum' => [0, 4.26, 0], 'wind_speed_10m_max' => [18.4, 30, 1]];
        return ['utc_offset_seconds' => $offset, 'hourly' => $h, 'daily' => $d];
    };

    $runner->add('forecast: the hourly list starts at the current local hour and holds 24 entries', function () use ($raw) {
        // 2026-10-19 10:30 UTC, place is UTC+2 -> local 12:30 -> first entry 12:00
        $now = gmmktime(10, 30, 0, 10, 19, 2026);
        $f = zprime_forecast_shape($raw(7200), $now);
        assert_equal(24, count($f['hourly']));
        assert_equal('2026-10-19T12:00', $f['hourly'][0]['t']);
        assert_equal('2026-10-20T11:00', $f['hourly'][23]['t']);
        assert_equal(22.0, $f['hourly'][0]['temp']);
        assert_equal('☀️', $f['hourly'][0]['icon']);
        assert_equal('Clear', $f['hourly'][0]['label']);
        assert_equal('🌧️', $f['hourly'][12]['icon']);
        assert_equal(12, $f['hourly'][0]['pop']);
    });

    $runner->add('forecast: the same instant in another time zone picks a different start hour', function () use ($raw) {
        $now = gmmktime(10, 30, 0, 10, 19, 2026);
        assert_equal('2026-10-19T04:00', zprime_forecast_shape($raw(-21600), $now)['hourly'][0]['t']);   // 04:30 local
    });

    $runner->add('forecast: a missing hourly value is skipped, bad daily rows are dropped, numbers are rounded', function () use ($raw) {
        $f = zprime_forecast_shape($raw(0), gmmktime(0, 0, 0, 10, 19, 2026));
        assert_false(in_array('2026-10-19T05:00', array_column($f['hourly'], 't'), true), 'a null temperature was kept');
        assert_equal(2, count($f['daily']));
        assert_equal(20.4, $f['daily'][0]['tmax']);
        assert_equal(4.3, $f['daily'][1]['precip']);
        assert_equal(80, $f['daily'][1]['pop']);
        assert_equal(30.0, (float)$f['daily'][1]['wind']);
    });

    $runner->add('forecast: unusable answers are refused', function () {
        assert_null(zprime_forecast_shape([], 0));
        assert_null(zprime_forecast_shape(['hourly' => ['time' => []], 'daily' => ['time' => []]], 0));
        assert_null(zprime_forecast_shape(['hourly' => 'x', 'daily' => 'y'], 0));
        // everything in the past: nothing to show
        assert_null(zprime_forecast_shape(['hourly' => ['time' => ['2000-01-01T00:00'], 'temperature_2m' => [1]], 'daily' => ['time' => ['2000-01-01'], 'temperature_2m_max' => [1], 'temperature_2m_min' => [0]]], time()));
    });

    $runner->add('weather icons: every known code has one, unknown codes get a neutral mark', function () {
        foreach ([0, 1, 2, 3, 45, 48, 51, 57, 61, 67, 71, 77, 80, 82, 85, 86, 95, 99] as $c) {
            assert_true(zprime_weather_icon($c) !== '·', "no icon for $c");
        }
        assert_equal('·', zprime_weather_icon(-1));
        assert_equal('·', zprime_weather_icon(1234));
    });
}

function zprime_functional_forecast(TestRunner $runner, string $baseUrl, WeatherStub $stub): void {
    $a = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($a, TestFixtures::USER_A, TestFixtures::PASS_A);
    $anon = new TestHttpClient($baseUrl);
    $cache = zprime_forecast_cache_file();
    @unlink($cache);
    @unlink($cache . '.fail');
    $stub->setMode('ok');

    $age = function (int $s) use ($cache) {
        $d = json_decode(file_get_contents($cache), true);
        $d['fetched'] = time() - $s;
        file_put_contents($cache, json_encode($d));
    };

    $runner->add('the first request fetches the forecast: 24 hours from now, 7 days from today', function () use ($a, $stub) {
        $before = $stub->requests();
        $res = $a->get('/api/forecast');
        assert_equal(200, $res['status']);
        $j = json_decode($res['body'], true);
        assert_equal($before + 1, $stub->requests());
        assert_equal(24, count($j['hourly']));
        assert_equal(7, count($j['daily']));
        assert_equal('C', $j['unit']);
        assert_equal('km/h', $j['speed_unit']);
        assert_equal(false, $j['stale']);
        // the stub's local time is UTC+2
        assert_equal(gmdate('Y-m-d\TH:00', time() + 7200), $j['hourly'][0]['t']);
        assert_equal(gmdate('Y-m-d', time() + 7200), $j['daily'][0]['date']);
        assert_equal('Clear', $j['daily'][0]['label']);
        assert_equal('⛈️', $j['daily'][5]['icon']);
        assert_equal(20.4, $j['daily'][0]['tmax']);
        assert_equal(11.6, $j['daily'][0]['tmin']);
    });

    $runner->add('requests inside the cache window do not call the weather service again', function () use ($a, $stub) {
        $before = $stub->requests();
        $a->get('/api/forecast');
        $a->get('/api/forecast');
        assert_equal($before, $stub->requests(), 'cache not used');
    });

    $runner->add('an expired forecast is refreshed', function () use ($a, $stub, $age) {
        $age(7200);
        $before = $stub->requests();
        assert_equal(200, $a->get('/api/forecast')['status']);
        assert_equal($before + 1, $stub->requests());
    });

    $runner->add('the page itself never waits on the weather service', function () use ($a, $stub) {
        $before = $stub->requests();
        $html = $a->get('/')['body'];
        assert_contains('data-forecast', $html);
        assert_contains('/api/forecast', $html);
        assert_equal($before, $stub->requests(), 'rendering the page called the weather service');
    });

    $runner->add('when the service is down the last forecast is served, marked stale, and not hammered', function () use ($a, $stub, $age) {
        $age(7200);
        $stub->setMode('fail');
        $j = json_decode($a->get('/api/forecast')['body'], true);
        assert_equal(true, $j['stale']);
        assert_equal(24, count($j['hourly']));
        $before = $stub->requests();
        for ($i = 0; $i < 4; $i++) $a->get('/api/forecast');
        assert_equal($before, $stub->requests(), 'retried a failing service on every request');
    });

    $runner->add('with no forecast at all and the service down the endpoint answers 503, then recovers', function () use ($a, $stub, $cache) {
        @unlink($cache);
        @unlink($cache . '.fail');
        assert_equal(503, $a->get('/api/forecast')['status']);
        $stub->setMode('ok');
        @unlink($cache . '.fail');
        assert_equal(200, $a->get('/api/forecast')['status'], 'did not recover');
    });

    $runner->add('the endpoint needs a signed-in account', function () use ($anon) {
        $r = $anon->get('/api/forecast');
        assert_true(in_array($r['status'], [301, 302, 303, 401, 403], true), 'anonymous got ' . $r['status']);
        assert_false(str_contains($r['body'], '"hourly"'));
    });
}
