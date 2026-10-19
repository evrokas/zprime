<?php

// ZPrime route handlers. Pure helpers (validation, Maps URLs, weather)
// live in dash_lib.php.

function zprime_current_user_id(): ?int {
    global $kernel;
    $uname = $kernel->getUserName();
    if(!$uname) return null;
    $account = usersClassEx::getUserAccount($uname);
    return $account ? (int)$account->getid() : null;
}

/** Home location settings from config/site.info.yaml, with safe defaults. */
function zprime_home_config(): array {
    global $kernel;
    $cfg = $kernel->getConfig();
    $home = $cfg['home'] ?? [];
    $unit = $cfg['temperature_unit'] ?? 'celsius';
    if(is_array($unit)) $unit = end($unit);
    $ttl = $cfg['weather_cache_seconds'] ?? 600;
    if(is_array($ttl)) $ttl = end($ttl);
    return [
        'label' => (string)($home['label'] ?? 'Home'),
        'lat' => (float)($home['lat'] ?? 0),
        'lon' => (float)($home['lon'] ?? 0),
        'unit' => $unit === 'fahrenheit' ? 'fahrenheit' : 'celsius',
        'ttl' => max(60, (int)$ttl),
        'tz' => (string)($cfg['tz'] ?? 'UTC'),
    ];
}

function zprime_back_home(): never {
    header('location: ' . rel_url('/'));
    exit();
}

function homepage($params) {
    // Straight to the sign-in form for a visitor who is not signed in;
    // everything on the page itself is rendered by the regions/modules.
    if(!SecurityClass::userLoggedIn()) {
        header('location: ' . rel_url('/login'));
        exit();
    }
    return '';
}

function zprime_api_weather($params) {
    $home = zprime_home_config();
    $w = zprime_weather_get($home, true);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if($w === null) {
        http_response_code(503);
        echo json_encode(['error' => 'unavailable']);
        exit();
    }
    echo json_encode([
        'temp' => round($w['temp'], 1),
        'unit' => $w['unit'],
        'label' => $w['label'],
        'is_day' => $w['is_day'],
        'stale' => $w['stale'],
        'fetched' => $w['fetched'],
    ]);
    exit();
}

function zprime_link_save($params) {
    global $kernel;
    if(($bad = csrfClass::requireValid())) return $bad;
    $uid = zprime_current_user_id();
    if($uid === null) zprime_back_home();

    $title = trim((string)($_POST['title'] ?? ''));
    $url = zprime_normalize_url((string)($_POST['url'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if($title === '') $title = $url !== null ? zprime_url_label($url) : '';
    if($url === null) {
        $kernel->addStatus('error', 'That website address is not valid. Use an http or https address.');
        zprime_back_home();
    }
    if($title === '' || mb_strlen($title) > 80) {
        $kernel->addStatus('error', 'Give the website a title of up to 80 characters.');
        zprime_back_home();
    }

    if($id > 0) {
        if(dashLinksClassEx::change($uid, $id, $title, $url)) $kernel->addStatus('notice', 'Website saved.');
        else $kernel->addStatus('error', 'That website no longer exists.');
    } else {
        dashLinksClassEx::create($uid, $title, $url);
        $kernel->addStatus('notice', 'Website added.');
    }
    zprime_back_home();
}

function zprime_link_delete($params) {
    global $kernel;
    if(($bad = csrfClass::requireValid())) return $bad;
    $uid = zprime_current_user_id();
    if($uid === null) zprime_back_home();

    if(dashLinksClassEx::remove($uid, (int)($params['id'] ?? 0))) $kernel->addStatus('notice', 'Website removed.');
    zprime_back_home();
}

function zprime_place_save($params) {
    global $kernel;
    if(($bad = csrfClass::requireValid())) return $bad;
    $uid = zprime_current_user_id();
    if($uid === null) zprime_back_home();

    $name = trim((string)($_POST['name'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if($name === '' || mb_strlen($name) > 80) {
        $kernel->addStatus('error', 'Give the place a name of up to 80 characters.');
        zprime_back_home();
    }

    $where = zprime_parse_place_input((string)($_POST['where'] ?? ''));
    if(!$where['ok']) {
        $kernel->addStatus('error', $where['error']);
        zprime_back_home();
    }

    if($id > 0) {
        if(dashPlacesClassEx::change($uid, $id, $name, $where['address'], $where['lat'], $where['lon'])) $kernel->addStatus('notice', 'Place saved.');
        else $kernel->addStatus('error', 'That place no longer exists.');
    } else {
        dashPlacesClassEx::create($uid, $name, $where['address'], $where['lat'], $where['lon']);
        $kernel->addStatus('notice', 'Place added.');
    }
    zprime_back_home();
}

function zprime_place_delete($params) {
    global $kernel;
    if(($bad = csrfClass::requireValid())) return $bad;
    $uid = zprime_current_user_id();
    if($uid === null) zprime_back_home();

    if(dashPlacesClassEx::remove($uid, (int)($params['id'] ?? 0))) $kernel->addStatus('notice', 'Place removed.');
    zprime_back_home();
}
