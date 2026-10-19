<?php

// ZPrime helpers that need no framework state, so tests can load them on
// their own: input validation, Google Maps URLs, weather lookup + cache.

/**
 * Normalises a website address typed by a person. Adds https:// when no
 * scheme was typed. Only http and https are accepted (never javascript:,
 * data:, file: ...). Returns null when the address is not usable.
 */
function zprime_normalize_url(string $raw): ?string {
    $raw = trim($raw);
    if($raw === '' || strlen($raw) > 2000) return null;
    if(preg_match('/[\x00-\x20\x7f]/', $raw)) return null;       // no spaces / control characters

    // No scheme typed -> https. "host:8080/x" looks like a scheme:rest
    // pair, so a host:port prefix is recognised first and treated as a host.
    $hasScheme = preg_match('#^[a-z][a-z0-9+.\-]*:#i', $raw) && !preg_match('#^[a-z0-9.\-]+:\d+(/|$)#i', $raw);
    if(!$hasScheme) {
        $raw = 'https://' . ltrim($raw, '/');
    }

    $parts = parse_url($raw);
    if($parts === false || empty($parts['scheme']) || empty($parts['host'])) return null;
    if(!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
    if(!preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/i', $parts['host']) && !preg_match('/^\[[0-9a-f:.]+\]$/i', $parts['host'])) return null;
    return $raw;
}

/** Host part without "www.", used as the secondary line on a website card. */
function zprime_url_label(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./i', '', $host);
}

/**
 * Reads what a person typed for a place: coordinates ("38.04, 23.81"), a
 * Google Maps link containing coordinates, or a plain street address.
 *
 * @return array{ok:bool, error?:string, lat?:?string, lon?:?string, address?:?string}
 */
function zprime_parse_place_input(string $raw): array {
    $raw = trim($raw);
    if($raw === '') return ['ok' => false, 'error' => 'Enter an address, coordinates or a Google Maps link.'];
    if(strlen($raw) > 2000) return ['ok' => false, 'error' => 'That is too long.'];

    $num = '(-?\d{1,3}(?:\.\d+)?)';

    $found = null;
    if(preg_match('/^' . $num . '\s*[,;\s]\s*' . $num . '$/', $raw, $m)) {
        $found = [$m[1], $m[2]];
    } elseif(preg_match('#^https?://#i', $raw)) {
        $text = rawurldecode($raw);
        if(preg_match('/@' . $num . ',' . $num . '/', $text, $m)
            || preg_match('/!3d' . $num . '!4d' . $num . '/', $text, $m)
            || preg_match('/[?&](?:q|query|destination|ll|daddr)=' . $num . ',\s*' . $num . '/', $text, $m)) {
            $found = [$m[1], $m[2]];
        } else {
            return ['ok' => false, 'error' => 'Could not read coordinates from that link. Open the place in Maps, copy the address or the coordinates, and paste them here.'];
        }
    }

    if($found !== null) {
        $lat = (float)$found[0];
        $lon = (float)$found[1];
        if($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['ok' => false, 'error' => 'Those coordinates are outside the valid range.'];
        }
        return ['ok' => true, 'lat' => sprintf('%.6F', $lat), 'lon' => sprintf('%.6F', $lon), 'address' => null];
    }

    if(preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $raw) || strlen($raw) > 255) {
        return ['ok' => false, 'error' => 'The address is not valid or longer than 255 characters.'];
    }
    return ['ok' => true, 'lat' => null, 'lon' => null, 'address' => $raw];
}

/** Text shown under a place's name. */
function zprime_place_where(array $place): string {
    if($place['lat'] !== null && $place['lat'] !== '' && $place['lon'] !== null && $place['lon'] !== '') {
        return rtrim(rtrim(number_format((float)$place['lat'], 6, '.', ''), '0'), '.') . ', '
             . rtrim(rtrim(number_format((float)$place['lon'], 6, '.', ''), '0'), '.');
    }
    return (string)($place['address'] ?? '');
}

/** Google Maps driving directions to a saved place. Opens the Maps app on a phone. */
function zprime_maps_url(array $place): string {
    $hasCoords = $place['lat'] !== null && $place['lat'] !== '' && $place['lon'] !== null && $place['lon'] !== '';
    $dest = $hasCoords
        ? sprintf('%.6F,%.6F', (float)$place['lat'], (float)$place['lon'])
        : (string)$place['address'];
    return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($dest) . '&travelmode=driving';
}

/** WMO weather interpretation code -> short label. */
function zprime_weather_label(int $code): string {
    static $labels = [
        0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Freezing fog',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle', 56 => 'Freezing drizzle', 57 => 'Freezing drizzle',
        61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain', 66 => 'Freezing rain', 67 => 'Freezing rain',
        71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow', 77 => 'Snow grains',
        80 => 'Light showers', 81 => 'Showers', 82 => 'Heavy showers', 85 => 'Snow showers', 86 => 'Heavy snow showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm, hail', 99 => 'Thunderstorm, hail',
    ];
    return $labels[$code] ?? 'Unknown';
}

function zprime_weather_cache_file(): string {
    $dir = __DIR__ . '/cache';
    if(!is_dir($dir)) @mkdir($dir, 0775, true);
    if(!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
    return $dir . '/zprime-weather.json';
}

/**
 * Cached weather reading for the home location, or null when there is none.
 * $fetch=false never touches the network (used while rendering the page);
 * $fetch=true refreshes a missing/stale cache from Open-Meteo, and falls
 * back to the stale reading if the service is unreachable.
 *
 * @param array{lat:float,lon:float,unit:string,ttl:int} $home
 * @return array{temp:float,unit:string,label:string,code:int,is_day:bool,fetched:int,stale:bool}|null
 */
function zprime_weather_get(array $home, bool $fetch): ?array {
    $file = zprime_weather_cache_file();
    $key = sprintf('%.4F,%.4F,%s', $home['lat'], $home['lon'], $home['unit']);

    $cached = null;
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if($raw !== false) {
        $d = json_decode($raw, true);
        if(is_array($d) && ($d['key'] ?? null) === $key && isset($d['temp'], $d['fetched'])) $cached = $d;
    }

    $fresh = $cached !== null && (time() - (int)$cached['fetched']) < $home['ttl'];
    if($fresh || !$fetch) {
        return $cached === null ? null : zprime_weather_shape($cached, !$fresh);
    }

    // After a failed fetch, do not try again for a minute: with the service
    // down, every open device would otherwise add its own 5 s timeout.
    $failFile = $file . '.fail';
    if(is_file($failFile) && (time() - (int)@filemtime($failFile)) < 60) {
        return $cached === null ? null : zprime_weather_shape($cached, true);
    }

    $base = getenv('ZPRIME_WEATHER_URL') ?: 'https://api.open-meteo.com/v1/forecast';
    $url = $base . '?' . http_build_query([
        'latitude' => sprintf('%.4F', $home['lat']),
        'longitude' => sprintf('%.4F', $home['lon']),
        'current' => 'temperature_2m,weather_code,is_day',
        'temperature_unit' => $home['unit'],
        'timezone' => 'auto',
    ]);

    $reading = null;
    $ch = curl_init($url);
    if($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'ZPrime/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if($body !== false && $status === 200) {
            $j = json_decode($body, true);
            $cur = is_array($j) ? ($j['current'] ?? null) : null;
            if(is_array($cur) && isset($cur['temperature_2m']) && is_numeric($cur['temperature_2m'])) {
                $reading = [
                    'key' => $key,
                    'temp' => (float)$cur['temperature_2m'],
                    'code' => (int)($cur['weather_code'] ?? -1),
                    'is_day' => !empty($cur['is_day']),
                    'fetched' => time(),
                ];
            }
        }
    }

    if($reading === null) {
        @touch($failFile);
        return $cached === null ? null : zprime_weather_shape($cached, true);
    }

    @unlink($failFile);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if(@file_put_contents($tmp, json_encode($reading)) !== false) @rename($tmp, $file);
    return zprime_weather_shape($reading, false);
}

function zprime_weather_shape(array $d, bool $stale): array {
    return [
        'temp' => (float)$d['temp'],
        'unit' => str_contains((string)$d['key'], 'fahrenheit') ? 'F' : 'C',
        'label' => zprime_weather_label((int)($d['code'] ?? -1)),
        'code' => (int)($d['code'] ?? -1),
        'is_day' => (bool)($d['is_day'] ?? true),
        'fetched' => (int)$d['fetched'],
        'stale' => $stale,
    ];
}
