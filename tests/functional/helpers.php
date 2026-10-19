<?php
/* Pure-function checks (no database, no server) for web/dash_lib.php, plus
 * the WeatherStub process wrapper the weather suite uses. */

function zprime_functional_helpers(TestRunner $runner): void {
    $runner->add('website addresses: scheme added, only http(s) accepted', function () {
        assert_equal('https://example.com', zprime_normalize_url('example.com'));
        assert_equal('http://a.b/c?d=1', zprime_normalize_url('http://a.b/c?d=1'));
        assert_equal('https://localhost:8080/x', zprime_normalize_url('localhost:8080/x'));
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,hi', 'file:///etc/passwd', 'ftp://x.y', 'https://', 'a b.com', '', str_repeat('a', 2001)] as $bad) {
            assert_null(zprime_normalize_url($bad), "accepted: $bad");
        }
    });

    $runner->add('places: coordinates, Maps links and addresses are read correctly', function () {
        $r = zprime_parse_place_input('38.04, 23.81');
        assert_true($r['ok']); assert_equal('38.040000', $r['lat']); assert_equal('23.810000', $r['lon']); assert_null($r['address']);

        $r = zprime_parse_place_input('https://www.google.com/maps/@37.98,23.72,15z');
        assert_true($r['ok']); assert_equal('37.980000', $r['lat']);

        $r = zprime_parse_place_input('https://www.google.com/maps/dir/?api=1&destination=38.0421%2C23.8105');
        assert_true($r['ok']); assert_equal('23.810500', $r['lon']);

        $r = zprime_parse_place_input('Kifisias 12, Marousi');
        assert_true($r['ok']); assert_null($r['lat']); assert_equal('Kifisias 12, Marousi', $r['address']);

        foreach (['', '95, 10', '38, 190', 'https://maps.app.goo.gl/abc'] as $bad) {
            assert_false(zprime_parse_place_input($bad)['ok'], "accepted: $bad");
        }
    });

    $runner->add('Google Maps driving URL uses coordinates when set, the address otherwise', function () {
        assert_equal(
            'https://www.google.com/maps/dir/?api=1&destination=38.042100%2C23.810500&travelmode=driving',
            zprime_maps_url(['lat' => '38.042100', 'lon' => '23.810500', 'address' => null])
        );
        assert_equal(
            'https://www.google.com/maps/dir/?api=1&destination=Kifisias%2012%2C%20Marousi&travelmode=driving',
            zprime_maps_url(['lat' => null, 'lon' => null, 'address' => 'Kifisias 12, Marousi'])
        );
    });

    $runner->add('weather codes map to labels', function () {
        assert_equal('Clear', zprime_weather_label(0));
        assert_equal('Thunderstorm', zprime_weather_label(95));
        assert_equal('Unknown', zprime_weather_label(1234));
    });
}

/** Runs tests/lib/weather_stub.php on its own port, with a request counter and a mode switch. */
class WeatherStub {
    private $process = null;
    private string $dir;
    private int $port = 8782;

    function start(): void {
        $this->dir = sys_get_temp_dir() . '/zprime_weather_stub_' . getmypid();
        @mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/count', '0');
        file_put_contents($this->dir . '/mode', 'ok');
        putenv('ZPRIME_STUB_DIR=' . $this->dir);

        $cmd = sprintf('exec php -S 127.0.0.1:%d %s', $this->port, escapeshellarg(__DIR__ . '/../lib/weather_stub.php'));
        $this->process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $this->port, $e, $s, 0.2);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('weather stub did not start');
    }

    function url(): string { return "http://127.0.0.1:{$this->port}/"; }
    function requests(): int { return (int)file_get_contents($this->dir . '/count'); }
    function setMode(string $mode): void { file_put_contents($this->dir . '/mode', $mode); }

    function stop(): void {
        if ($this->process === null) return;
        $st = proc_get_status($this->process);
        if ($st['running']) posix_kill($st['pid'], SIGTERM);
        proc_close($this->process);
        $this->process = null;
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    function __destruct() { $this->stop(); }
}
