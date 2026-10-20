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

    // Day conditions: build one day of hourly codes, 'hour => code' for the hours given, 0 elsewhere.
    $dayCode = function (array $byHour, int $daily = 45) {
        $h = ['time' => [], 'temperature_2m' => [], 'weather_code' => [], 'precipitation_probability' => []];
        for ($hr = 0; $hr < 24; $hr++) {
            $h['time'][] = sprintf('2026-10-21T%02d:00', $hr);
            $h['temperature_2m'][] = 15;
            $h['weather_code'][] = $byHour[$hr] ?? 0;
            $h['precipitation_probability'][] = 0;
        }
        $d = ['time' => ['2026-10-21'], 'weather_code' => [$daily], 'temperature_2m_max' => [20], 'temperature_2m_min' => [10],
              'precipitation_probability_max' => [0], 'precipitation_sum' => [0], 'wind_speed_10m_max' => [5]];
        return zprime_forecast_shape(['utc_offset_seconds' => 0, 'hourly' => $h, 'daily' => $d], gmmktime(0, 0, 0, 10, 20, 2026))['daily'][0]['code'];
    };
    $range = fn(int $from, int $to, int $code) => array_fill_keys(range($from, $to), $code);

    $runner->add('day condition: a patch of early-morning fog does not make a clear day foggy', function () use ($dayCode, $range) {
        assert_equal(0, $dayCode($range(4, 8, 45), 45), 'service said fog for the day');
        assert_equal(0, $dayCode($range(0, 9, 45), 45));
    });
    $runner->add('day condition: fog that fills the daytime still counts', function () use ($dayCode, $range) {
        assert_equal(45, $dayCode($range(0, 23, 45), 45));
        assert_equal(48, $dayCode($range(7, 17, 48), 48));        // 11 of 15 daytime hours
    });
    $runner->add('day condition: rain, snow, storm and drizzle win when they last two hours; one stray hour is ignored', function () use ($dayCode, $range) {
        assert_equal(61, $dayCode($range(10, 12, 61), 61));
        assert_equal(0, $dayCode([10 => 61], 61), 'a single rainy hour decided the day');
        assert_equal(73, $dayCode($range(8, 9, 73), 73));
        assert_equal(51, $dayCode($range(8, 10, 51), 51));
        assert_equal(95, $dayCode($range(9, 10, 95) + $range(11, 16, 63), 95), 'storm should beat rain');
        assert_equal(63, $dayCode($range(11, 16, 63) + $range(8, 9, 51) + $range(0, 4, 45), 63), 'rain should beat drizzle and fog');
        assert_equal(51, $dayCode($range(8, 10, 51) + $range(0, 23, 45), 51), 'drizzle should beat fog');
    });
    $runner->add('day condition: otherwise the most common sky, ties to the cloudier', function () use ($dayCode, $range) {
        assert_equal(2, $dayCode($range(7, 12, 2) + $range(13, 16, 3), 3));          // 6 partly vs 4 overcast vs 5 clear
        assert_equal(3, $dayCode($range(7, 14, 3) + $range(15, 21, 0), 3));          // 8 overcast vs 7 clear
        assert_equal(3, $dayCode($range(7, 13, 2) + $range(14, 20, 3) + [21 => 0], 3), 'a tie between partly cloudy and overcast should go to overcast');
        assert_equal(1, $dayCode($range(7, 21, 1), 1));
    });
    $runner->add('day condition: hours outside 07:00-21:00 are ignored, and with no hourly data the service code is used', function () use ($dayCode, $range) {
        assert_equal(0, $dayCode($range(22, 23, 95) + $range(0, 6, 65), 95), 'night weather decided the day');
        $d = ['time' => ['2030-01-01'], 'weather_code' => [3], 'temperature_2m_max' => [1], 'temperature_2m_min' => [0]];
        $h = ['time' => ['2030-01-02T12:00'], 'temperature_2m' => [1], 'weather_code' => [0]];
        assert_equal(3, zprime_forecast_shape(['hourly' => $h, 'daily' => $d], gmmktime(0, 0, 0, 1, 1, 2030))['daily'][0]['code']);
    });

    $runner->add('weather icons: every known code has one, unknown codes get a neutral mark', function () {
        foreach ([0, 1, 2, 3, 45, 48, 51, 57, 61, 67, 71, 77, 80, 82, 85, 86, 95, 99] as $c) {
            assert_true(zprime_weather_icon($c) !== '·', "no icon for $c");
        }
        assert_equal('🌁', zprime_weather_icon(45), 'fog uses the foggy emoji');
        assert_equal('🌁', zprime_weather_icon(48));
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
        assert_equal('🌁', $j['daily'][6]['icon']);                       // the stub's seventh day is fog
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
        assert_contains('data-icons="emoji"', $html);
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
