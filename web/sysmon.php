<?php

// System status: JSON endpoints behind the dashboard's "System" block. The browser never sees an agent's
// URL or token; this server asks the agents (or reads the local database) and relays the answer.

/** The `sysmonitor:` block, or the test-only JSON override in ZPRIME_SYSMON_CONFIG_JSON. */
function zprime_sysmon_config(): array {
    global $kernel;
    $env = getenv('ZPRIME_SYSMON_CONFIG_JSON');
    if($env) {
        $j = json_decode($env, true);
        return is_array($j) ? $j : [];
    }
    $c = $kernel->getConfig('sysmonitor');
    return is_array($c) ? $c : [];
}

function zprime_sysmon_ttl(array $cfg): int {
    $ttl = $cfg['cache_seconds'] ?? 30;
    return is_numeric($ttl) ? max(5, min(600, (int)$ttl)) : 30;
}

/** Machine details are sensitive: the owner only, unless `allow_all_users: true`. */
function zprime_sysmon_allowed(array $cfg): bool {
    if(!SecurityClass::userLoggedIn()) return false;
    if(!empty($cfg['allow_all_users'])) return true;
    return class_exists('rbacClass') && rbacClass::currentUserIsSuperuser();
}

function zprime_sysmon_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit();
}

function zprime_api_sysmon($params) {
    $cfg = zprime_sysmon_config();
    if(!zprime_sysmon_allowed($cfg)) zprime_sysmon_json(403, ['error' => 'forbidden']);
    $hosts = zprime_sysmon_hosts($cfg);
    if(!$hosts) zprime_sysmon_json(404, ['error' => 'not configured']);
    $ttl = zprime_sysmon_ttl($cfg);
    $out = [];
    foreach($hosts as $h) $out[] = zprime_sysmon_overview($h, $ttl);
    zprime_sysmon_json(200, ['now' => time(), 'hosts' => $out]);
}

function zprime_api_sysmon_series($params) {
    $cfg = zprime_sysmon_config();
    if(!zprime_sysmon_allowed($cfg)) zprime_sysmon_json(403, ['error' => 'forbidden']);
    $hosts = zprime_sysmon_hosts($cfg);
    $host = $hosts[(int)($params['host'] ?? -1)] ?? null;
    if($host === null) zprime_sysmon_json(404, ['error' => 'unknown host']);
    $res = zprime_sysmon_series($host, (string)($_GET['metric'] ?? ''), (string)($_GET['range'] ?? '24h'));
    if($res === null) zprime_sysmon_json(503, ['error' => 'unavailable']);
    zprime_sysmon_json(200, $res);
}
