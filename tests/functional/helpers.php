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

    // The private-address guard must be ON in this process (the app's test server turns it off to reach the stub site).
    $runner->add('favicon fetch refuses private, local and reserved addresses', function () {
        assert_false(zprime_fetch_allow_private(), 'the guard is switched off in the test runner');
        foreach (['127.0.0.1', '127.1.2.3', '10.0.0.5', '172.16.9.1', '192.168.1.1', '169.254.169.254', '0.0.0.0',
                  '100.64.0.1', '224.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1',
                  '::ffff:127.0.0.1', '::ffff:10.0.0.1', '2002:7f00:1::1', '64:ff9b::7f00:1', '2001:db8::1'] as $ip) {
            assert_false(zprime_ip_is_public($ip), "$ip was treated as public");
        }
        foreach (['8.8.8.8', '93.184.216.34', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
            assert_true(zprime_ip_is_public($ip), "$ip was refused");
        }
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data/',
                  'http://10.0.0.1/', 'http://2130706433/', 'http://0x7f.1/', 'http://example.com:8080/', 'http://example.com:22/',
                  'file:///etc/passwd', 'ftp://example.com/x', 'gopher://127.0.0.1/'] as $url) {
            $r = zprime_http_get($url, 1000);
            assert_false($r['ok'], "$url was fetched");
            assert_equal('blocked', $r['error'], "$url failed for another reason than the guard: " . $r['error']);
        }
        $r = zprime_favicon_fetch('http://169.254.169.254/');
        assert_false($r['ok']);
        assert_contains('private or local', $r['error']);
    });

    $runner->add('relative icon addresses resolve like a browser would', function () {
        $base = 'https://example.com/a/b/page.html?x=1';
        assert_equal('https://example.com/a/b/i.png', zprime_resolve_url('i.png', $base));
        assert_equal('https://example.com/a/i.png', zprime_resolve_url('../i.png', $base));
        assert_equal('https://example.com/i.png', zprime_resolve_url('/i.png', $base));
        assert_equal('https://cdn.example.net/i.png', zprime_resolve_url('//cdn.example.net/i.png', $base));
        assert_equal('http://other.test/i.png', zprime_resolve_url('http://other.test/i.png', $base));
        assert_equal('https://example.com/i.png', zprime_resolve_url('../../../../i.png', $base));
        assert_null(zprime_resolve_url('javascript:alert(1)', $base));
        assert_null(zprime_resolve_url('data:image/png;base64,AAAA', $base));
        assert_null(zprime_resolve_url('', $base));
    });

    $runner->add('favicon candidates: best size first, SVG skipped, /favicon.ico last', function () {
        $html = '<LINK REL="icon" HREF="/small.png" sizes="16x16">'
              . "<link rel='apple-touch-icon' href='/touch.png' sizes='180x180'>"
              . '<link rel="icon" href="/mid.png" sizes="48x48">'
              . '<link rel="icon" type="image/svg+xml" href="/vec.svg">'
              . '<link rel="mask-icon" href="/mask.svg">'
              . '<link rel="stylesheet" href="/style.css">'
              . '<link rel="shortcut icon" href="/unknown.ico">';
        $c = zprime_favicon_candidates($html, 'https://example.com/');
        assert_equal([
            'https://example.com/mid.png',        // 48 is closest to 64
            'https://example.com/unknown.ico',    // unknown size beats far-off ones
            'https://example.com/small.png',
            'https://example.com/touch.png',
            'https://example.com/favicon.ico',
        ], $c);
        assert_equal(['https://example.com/favicon.ico'], zprime_favicon_candidates('', 'https://example.com/deep/page'));
    });

    $runner->add('icon bytes: only small PNG/GIF/JPEG/WebP/ICO pass; SVG, HTML and oversize are refused', function () {
        $im = imagecreatetruecolor(16, 16);
        ob_start(); imagepng($im); $png = (string)ob_get_clean();
        assert_equal('image/png', zprime_icon_mime($png));
        ob_start(); imagegif($im); assert_equal('image/gif', zprime_icon_mime((string)ob_get_clean()));
        ob_start(); imagejpeg($im); assert_equal('image/jpeg', zprime_icon_mime((string)ob_get_clean()));
        $ico = pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($png), 22) . $png;
        assert_equal('image/vnd.microsoft.icon', zprime_icon_mime($ico));
        assert_null(zprime_icon_mime('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        assert_null(zprime_icon_mime('<html>not an image</html>'));
        assert_null(zprime_icon_mime(''));
        assert_null(zprime_icon_mime($png . str_repeat("\0", 70000)), 'an oversize file was accepted');
        $big = imagecreatetruecolor(2000, 2000);
        ob_start(); imagepng($big); assert_null(zprime_icon_mime((string)ob_get_clean()), 'a huge image was accepted');
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

/** Runs tests/lib/sysmon_stub.php (a fake sysmonitor agent) on its own port. */
class SysmonStub {
    private $process = null;
    private string $dir;
    private int $port = 8784;

    function start(): void {
        $this->dir = sys_get_temp_dir() . '/zprime_sysmon_stub_' . getmypid();
        @mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/count', '0');
        file_put_contents($this->dir . '/mode', 'ok');
        putenv('ZPRIME_STUB_DIR=' . $this->dir);

        $cmd = sprintf('exec php -S 127.0.0.1:%d %s', $this->port, escapeshellarg(__DIR__ . '/../lib/sysmon_stub.php'));
        $this->process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $this->port, $e, $s, 0.2);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('sysmon stub did not start');
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

/** Runs tests/lib/icon_site.php (a fake website) on its own port. */
class IconSite {
    private $process = null;
    private string $dir;
    private int $port = 8783;

    function start(): void {
        $this->dir = sys_get_temp_dir() . '/zprime_icon_site_' . getmypid();
        @mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/mode', 'icon');
        putenv('ZPRIME_ICON_SITE_DIR=' . $this->dir);
        $cmd = sprintf('exec php -S 127.0.0.1:%d %s', $this->port, escapeshellarg(__DIR__ . '/../lib/icon_site.php'));
        $this->process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $this->port, $e, $s, 0.2);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('icon site did not start');
    }

    /** Address of a page on the fake site; $host is "127.0.0.1" (has /favicon.ico) or "localhost" (does not). */
    function url(string $path, string $host = '127.0.0.1'): string { return "http://{$host}:{$this->port}{$path}"; }
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
