<?php
/* The home temperature: fetched from the (stubbed) service, cached, served
 * stale when the service is down, and never retried in a burst. */

function zprime_functional_weather(TestRunner $runner, string $baseUrl, WeatherStub $stub): void {
    $a = new TestHttpClient($baseUrl);
    TestFixtures::loginAs($a, TestFixtures::USER_A, TestFixtures::PASS_A);
    $cache = zprime_weather_cache_file();

    $ageCache = function (int $seconds) use ($cache) {
        $d = json_decode(file_get_contents($cache), true);
        $d['fetched'] = time() - $seconds;
        file_put_contents($cache, json_encode($d));
    };

    $runner->add('first request fetches the reading and returns JSON', function () use ($a, $stub) {
        $before = $stub->requests();
        $res = $a->get('/api/weather');
        assert_equal(200, $res['status']);
        $j = json_decode($res['body'], true);
        assert_equal(21.4, $j['temp']);
        assert_equal('C', $j['unit']);
        assert_equal('Partly cloudy', $j['label']);
        assert_equal(false, $j['stale']);
        assert_equal($before + 1, $stub->requests());
    });

    $runner->add('a second request inside the cache window does not call the service again', function () use ($a, $stub) {
        $before = $stub->requests();
        $a->get('/api/weather');
        $a->get('/api/weather');
        assert_equal($before, $stub->requests(), 'cache was not used');
    });

    $runner->add('the page itself shows the cached temperature without waiting on the network', function () use ($a, $stub) {
        $before = $stub->requests();
        $html = $a->get('/')['body'];
        assert_contains('21°C', $html);
        assert_contains('Partly cloudy', $html);
        assert_equal($before, $stub->requests(), 'rendering the page called the weather service');
    });

    $runner->add('an expired reading is refreshed', function () use ($a, $stub, $ageCache) {
        $ageCache(3600);
        $before = $stub->requests();
        assert_equal(200, $a->get('/api/weather')['status']);
        assert_equal($before + 1, $stub->requests());
    });

    $runner->add('when the service is down the last reading is served, marked stale', function () use ($a, $stub, $ageCache) {
        $ageCache(3600);
        $stub->setMode('fail');
        $res = $a->get('/api/weather');
        assert_equal(200, $res['status']);
        $j = json_decode($res['body'], true);
        assert_equal(true, $j['stale']);
        assert_equal(21.4, $j['temp']);
    });

    $runner->add('after a failure the service is not hammered for a minute', function () use ($a, $stub) {
        $before = $stub->requests();
        for ($i = 0; $i < 4; $i++) $a->get('/api/weather');
        assert_equal($before, $stub->requests(), 'retried the failing service on every request');
    });

    $runner->add('with no reading at all and the service down, the endpoint answers 503', function () use ($a, $stub, $cache) {
        @unlink($cache);
        @unlink($cache . '.fail');
        $res = $a->get('/api/weather');
        assert_equal(503, $res['status']);
        assert_equal('unavailable', json_decode($res['body'], true)['error'] ?? null);
        $stub->setMode('ok');
        @unlink($cache . '.fail');
        assert_equal(200, $a->get('/api/weather')['status'], 'did not recover once the service came back');
    });
}
