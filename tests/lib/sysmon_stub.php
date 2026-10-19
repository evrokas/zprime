<?php
/* Stand-in for a sysmonitor agent (web/api.php), run under `php -S` by SysmonStub. Requires the bearer
 * token, counts requests in $STUB_DIR/count and answers according to $STUB_DIR/mode ("ok" | "fail"). */
$dir = getenv('ZPRIME_STUB_DIR');
file_put_contents("$dir/count", (string)((int)@file_get_contents("$dir/count") + 1));
header('Content-Type: application/json');
if (trim((string)@file_get_contents("$dir/mode")) === 'fail') { http_response_code(500); echo '{"error":"down"}'; exit; }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-sys-test') { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }

$now = time();
$action = $_GET['action'] ?? 'info';
if ($action === 'summary') {
    $it = fn($m, $i, $v, $u, $mx = null) => ['metric' => $m, 'instance' => $i, 'unit' => $u, 'value' => $v, 'ts' => $now - 20, 'age' => 20, 'min24h' => $v - 5, 'max24h' => $mx ?? $v + 5, 'avg24h' => $v, 'interval_min' => 1];
    echo json_encode(['host' => 'stub', 'now' => $now, 'items' => [
        $it('cpu.load', 'total', 42.5, '%'), $it('mem.used_pct', '', 60.0, '%'), $it('mem.available_bytes', '', 3.2e9, 'B'),
        $it('disk.used_pct', '/', 91.0, '%'), $it('disk.free_bytes', '/', 9e9, 'B'), $it('temp.c', 'coretemp/Package', 55.5, '°C'),
        $it('net.rx_bps', 'eth0', 120000, 'B/s'), $it('net.tx_bps', 'eth0', 30000, 'B/s'), $it('uptime.s', '', 90000, 's'),
    ]]);
    exit;
}
if ($action === 'query') {
    $from = (int)$_GET['from']; $to = (int)$_GET['to'];
    $bk = ($_GET['bucket'] ?? '300') === 'auto' ? 300 : (int)$_GET['bucket'];
    $mk = function ($inst, $base) use ($from, $to, $bk) {
        $p = [];
        for ($t = intdiv($from, $bk) * $bk, $i = 0; $t < $to && $i < 300; $t += $bk, $i++) $p[] = [$t, $base + ($i % 7), $base, $base + 7, 5];
        return ['instance' => $inst, 'points' => $p];
    };
    $series = ($_GET['metric'] ?? '') === 'disk.used_pct' ? [$mk('/', 80), $mk('/data', 20)] : [$mk(($_GET['instance'] ?? '') ?: 'total', 30)];
    echo json_encode(['host' => 'stub', 'metric' => $_GET['metric'], 'unit' => '%', 'from' => $from, 'to' => $to, 'bucket' => $bk,
        'columns' => ['ts', 'avg', 'min', 'max', 'n'], 'series' => $series]);
    exit;
}
echo json_encode(['host' => 'stub', 'now' => $now]);
