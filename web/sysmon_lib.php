<?php

// System-status helpers: talk to sysmonitor (https://github.com/evrokas/sysmonitor) agents.
// Framework-independent so the test suite can load it on its own.
// A host is either remote (its api.php URL + bearer token) or local (this machine's sysmonitor
// checkout, read in-process: no token, no HTTP).

const ZPRIME_SYSMON_RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000, '90d' => 7776000];
const ZPRIME_SYSMON_SPARK = ['cpu.load' => 'total', 'mem.used_pct' => ''];

/**
 * Valid hosts from the `sysmonitor:` config block, keyed by position (the id the browser uses).
 * Entries that are unusable are dropped, never half-used.
 *
 * @return array<int,array{id:int,name:string,local:bool,url:string,token:string,path:string}>
 */
function zprime_sysmon_hosts(array $cfg): array {
    $out = [];
    $list = $cfg['hosts'] ?? [];
    if(!is_array($list)) return [];
    foreach(array_values($list) as $i => $h) {
        if(!is_array($h)) continue;
        $name = trim((string)($h['name'] ?? ''));
        if($name === '' || mb_strlen($name) > 60) continue;
        $host = ['id' => $i, 'name' => $name, 'local' => false, 'url' => '', 'token' => '', 'path' => ''];
        if(!empty($h['local'])) {
            $path = rtrim((string)($cfg['sysmonitor_path'] ?? ''), '/');
            if($path === '') continue;
            $host['local'] = true;
            $host['path'] = $path;
        } else {
            $url = (string)($h['url'] ?? '');
            $token = (string)($h['token'] ?? '');
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            // Plain http only when the entry says so explicitly (a trusted LAN); the token travels with it.
            if($token === '' || !($scheme === 'https' || ($scheme === 'http' && !empty($h['allow_http'])))) continue;
            if(!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $host['url'] = $url;
            $host['token'] = $token;
        }
        $out[$i] = $host;
    }
    return $out;
}

/** @return array{ok:bool,status:int,body:?array} */
function zprime_sysmon_call(array $host, array $query): array {
    $fail = ['ok' => false, 'status' => 0, 'body' => null];

    if($host['local']) {
        $boot = $host['path'] . '/src/bootstrap.php';
        if(!is_file($boot)) return $fail;
        try {
            require_once $boot;
            $cfg = \Sysmon\Config::load($host['path'] . '/config.php');
            [$code, $body] = (new \Sysmon\Api($cfg))->dispatch($query);
        } catch(\Throwable $e) {
            error_log('zprime sysmon (local): ' . $e->getMessage());
            return $fail;
        }
        return ['ok' => $code === 200, 'status' => $code, 'body' => $code === 200 ? $body : null];
    }

    $url = $host['url'] . (str_contains($host['url'], '?') ? '&' : '?') . http_build_query($query);
    $ch = curl_init($url);
    if($ch === false) return $fail;
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $host['token'], 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,        // a redirect would carry the token somewhere unvetted
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'ZPrime/1.0',
        CURLOPT_WRITEFUNCTION => function($c, $chunk) use (&$buf) {
            $buf .= $chunk;
            return strlen($buf) > 2000000 ? 0 : strlen($chunk);   // refuse absurdly large replies
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($ok === false || $status !== 200) return ['ok' => false, 'status' => $status, 'body' => null];
    $j = json_decode($buf, true);
    return is_array($j) ? ['ok' => true, 'status' => 200, 'body' => $j] : $fail;
}

/** Outside the web root: machine statistics must not be fetchable by URL (web/cache is served). */
function zprime_sysmon_cache_dir(): string {
    $dir = sys_get_temp_dir() . '/zprime-sysmon-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'u');
    if(!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/**
 * Cached result of $fn (which returns an array, or null on failure). A failure is not retried for a
 * minute, so a host that is down costs one timeout per minute, not one per open device; the last good
 * value is served meanwhile. Returns [data, fetched_at, stale] or null when there is nothing at all.
 */
function zprime_sysmon_cached(string $key, int $ttl, callable $fn): ?array {
    $base = zprime_sysmon_cache_dir() . '/zprime-sysmon-' . sha1($key);
    $file = $base . '.json';
    $cached = null;
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if($raw !== false) {
        $d = json_decode($raw, true);
        if(is_array($d) && isset($d['fetched'], $d['data'])) $cached = $d;
    }
    $age = $cached ? time() - (int)$cached['fetched'] : PHP_INT_MAX;
    if($cached && $age < $ttl) return [$cached['data'], (int)$cached['fetched'], false];

    if(is_file($base . '.fail') && time() - (int)@filemtime($base . '.fail') < 60) {
        return $cached ? [$cached['data'], (int)$cached['fetched'], true] : null;
    }
    $data = $fn();
    if($data === null) {
        @touch($base . '.fail');
        return $cached ? [$cached['data'], (int)$cached['fetched'], true] : null;
    }
    @unlink($base . '.fail');
    $tmp = $file . '.' . getmypid() . '.tmp';
    $now = time();
    if(@file_put_contents($tmp, json_encode(['fetched' => $now, 'data' => $data])) !== false) @rename($tmp, $file);
    return [$data, $now, false];
}

function zprime_sysmon_host_key(array $host): string {
    return $host['id'] . '|' . $host['name'] . '|' . $host['url'] . '|' . $host['path'] . '|' . sha1($host['token']);
}

/**
 * Everything the status cards need for one host: latest values and small sparklines.
 *
 * @return array{id:int,name:string,local:bool,state:string,fetched:int,items:array,spark:array}
 */
function zprime_sysmon_overview(array $host, int $ttl): array {
    $r = zprime_sysmon_cached('overview|' . zprime_sysmon_host_key($host), $ttl, function() use ($host) {
        $sum = zprime_sysmon_call($host, ['action' => 'summary']);
        if(!$sum['ok'] || !isset($sum['body']['items'])) return null;
        $spark = [];
        $to = time();
        foreach(ZPRIME_SYSMON_SPARK as $metric => $inst) {
            $q = ['action' => 'query', 'metric' => $metric, 'from' => $to - 10800, 'to' => $to, 'bucket' => 300];
            if($inst !== '') $q['instance'] = $inst;
            $res = zprime_sysmon_call($host, $q);
            if($res['ok'] && !empty($res['body']['series'][0]['points'])) {
                $spark[$metric] = array_map(fn($p) => [(int)$p[0], round((float)$p[1], 2)], $res['body']['series'][0]['points']);
            }
        }
        return ['items' => $sum['body']['items'], 'spark' => $spark];
    });

    $base = ['id' => $host['id'], 'name' => $host['name'], 'local' => $host['local']];
    if($r === null) return $base + ['state' => 'offline', 'fetched' => 0, 'items' => [], 'spark' => []];
    [$data, $fetched, $stale] = $r;
    $newest = 0;
    foreach($data['items'] as $it) $newest = max($newest, (int)($it['ts'] ?? 0));
    // 'silent': the machine answers but its collector stopped writing.
    $state = $stale ? 'stale' : (($newest && time() - $newest > 600) ? 'silent' : 'ok');
    return $base + ['state' => $state, 'fetched' => $fetched, 'items' => $data['items'], 'spark' => $data['spark']];
}

/** One metric over a preset range, every instance. Null if the host cannot answer. */
function zprime_sysmon_series(array $host, string $metric, string $range, int $ttl = 15): ?array {
    if(!isset(ZPRIME_SYSMON_RANGES[$range]) || !preg_match('/^[a-z0-9_.]{1,40}$/', $metric)) return null;
    $r = zprime_sysmon_cached('series|' . zprime_sysmon_host_key($host) . "|$metric|$range", $ttl, function() use ($host, $metric, $range) {
        $to = time();
        $res = zprime_sysmon_call($host, ['action' => 'query', 'metric' => $metric, 'from' => $to - ZPRIME_SYSMON_RANGES[$range], 'to' => $to, 'bucket' => 'auto']);
        return $res['ok'] ? $res['body'] : null;
    });
    return $r === null ? null : $r[0] + ['stale' => $r[2]];
}
