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

/* ---------------------------------------------------------------------
 * Favicons
 *
 * The server fetches a site's icon (the browser never contacts the site,
 * so the dashboard leaks nothing to it and works behind any CSP) and keeps
 * it in dash_links.icon. Because the address to fetch is typed by a person,
 * every request here is guarded against being steered at the server's own
 * network (SSRF): only http(s) on ports 80/443, every address the host name
 * resolves to must be public, the checked address is the one connected to
 * (no second DNS lookup to swap it), redirects are followed by hand and each
 * hop is checked again, and sizes and times are capped.
 * ------------------------------------------------------------------- */

const ZPRIME_ICON_MAX_BYTES = 65536;
const ZPRIME_PAGE_MAX_BYTES = 524288;
const ZPRIME_ICON_MAX_PIXELS = 1024;

/** Test-only escape hatch: lets the fetcher reach loopback/private hosts and any port. Never set in production. */
function zprime_fetch_allow_private(): bool {
    return getenv('ZPRIME_FETCH_ALLOW_PRIVATE') === '1';
}

/** True for an address on the public internet. Private, loopback, link-local, reserved and embedded-IPv4 ranges are refused. */
function zprime_ip_is_public(string $ip): bool {
    if(stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if(filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = $v4;
    }
    if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;

    if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        // Carrier-grade NAT, IETF protocol, benchmarking, documentation, multicast.
        foreach([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['192.0.2.0', 24],
                 ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$base, $bits]) {
            $mask = -1 << (32 - $bits);
            if(($n & $mask) === (ip2long($base) & $mask)) return false;
        }
        return true;
    }

    $bin = inet_pton($ip);
    if($bin === false) return false;
    $b0 = ord($bin[0]);
    $b1 = ord($bin[1]);
    if($b0 === 0xff) return false;                                  // multicast
    if($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return false;        // link-local
    if(($b0 & 0xfe) === 0xfc) return false;                         // unique local
    if($b0 === 0x20 && $b1 === 0x02) return false;                  // 6to4 (embeds an IPv4 address)
    if(strncmp($bin, hex2bin('20010db8'), 4) === 0) return false;   // documentation
    if(strncmp($bin, hex2bin('0064ff9b'), 4) === 0) return false;   // NAT64 (embeds an IPv4 address)
    return true;
}

/** The address to connect to for $host, or null when it is unknown or any of its addresses is not public. */
function zprime_resolve_host(string $host): ?string {
    $h = trim($host, '[]');
    $allow = zprime_fetch_allow_private();

    if(filter_var($h, FILTER_VALIDATE_IP)) {
        return ($allow || zprime_ip_is_public($h)) ? $h : null;
    }

    $ips = [];
    foreach((@dns_get_record($h, DNS_A | DNS_AAAA) ?: []) as $r) {
        $ip = $r['ip'] ?? ($r['ipv6'] ?? null);
        if($ip) $ips[] = $ip;
    }
    if(!$ips) {
        $g = @gethostbyname($h);
        if($g !== $h && filter_var($g, FILTER_VALIDATE_IP)) $ips[] = $g;
    }
    if(!$ips) return null;
    if(!$allow) foreach($ips as $ip) if(!zprime_ip_is_public($ip)) return null;

    foreach($ips as $ip) if(strpos($ip, ':') === false) return $ip;    // prefer IPv4
    return $ips[0];
}

/** Resolves $href (absolute, //host, /path, relative, ?query) against $base. Null if the result is not http(s). */
function zprime_resolve_url(string $href, string $base): ?string {
    $href = trim($href);
    if($href === '') return null;
    if(preg_match('#^https?://#i', $href)) return $href;
    $b = parse_url($base);
    if(!$b || empty($b['scheme']) || empty($b['host'])) return null;
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    if(strpos($href, '//') === 0) return $b['scheme'] . ':' . $href;
    if(preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) return null;        // mailto:, javascript:, data: ...
    if($href[0] === '/') return $origin . $href;
    if($href[0] === '?') return $origin . ($b['path'] ?? '/') . $href;

    $dir = preg_replace('#[^/]*$#', '', $b['path'] ?? '/');
    $path = $dir . $href;
    $query = '';
    if(($q = strpos($path, '?')) !== false) { $query = substr($path, $q); $path = substr($path, 0, $q); }
    $out = [];
    foreach(explode('/', $path) as $seg) {
        if($seg === '..') array_pop($out);
        elseif($seg !== '.') $out[] = $seg;
    }
    $path = implode('/', $out);
    if($path === '' || $path[0] !== '/') $path = '/' . $path;
    return $origin . $path . $query;
}

/**
 * One guarded GET, following up to $maxRedirects redirects by hand.
 *
 * @return array{ok:bool, status:int, body:string, final_url:string, truncated:bool, error:?string}
 */
function zprime_http_get(string $url, int $maxBytes, bool $truncateOk = false, int $maxRedirects = 4): array {
    $result = ['ok' => false, 'status' => 0, 'body' => '', 'final_url' => $url, 'truncated' => false, 'error' => null];
    $allow = zprime_fetch_allow_private();

    for($hop = 0; $hop <= $maxRedirects; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if(!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $result['error'] = 'blocked';
            return $result;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if(!$allow && !in_array($port, [80, 443], true)) {
            $result['error'] = 'blocked';
            return $result;
        }
        $ip = zprime_resolve_host($host);
        if($ip === null) {
            $result['error'] = 'blocked';
            return $result;
        }

        $body = '';
        $truncated = false;
        $location = null;
        $ch = curl_init($url);
        if($ch === false) { $result['error'] = 'unreachable'; return $result; }
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ZPrime favicon fetcher)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,image/*;q=0.9,*/*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$location) {
                if(stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$truncated, $maxBytes) {
                if(strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;
                    $body .= substr($chunk, 0, max(0, $maxBytes - strlen($body)));
                    return 0;                                   // stop the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        if($truncated && $truncateOk) {
            // the part we kept is all that is wanted (the <head> of a page)
        } elseif($truncated) {
            $result['status'] = $status; $result['truncated'] = true; $result['final_url'] = $url; $result['error'] = 'too_large';
            return $result;
        } elseif($curlErr !== 0 || $status === 0) {
            $result['error'] = 'unreachable';
            return $result;
        }

        if($status >= 300 && $status < 400 && $location !== null && $location !== '') {
            $next = zprime_resolve_url($location, $url);
            if($next === null) { $result['error'] = 'blocked'; return $result; }
            $url = $next;
            continue;
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'final_url' => $url,
                'truncated' => $truncated, 'error' => ($status >= 200 && $status < 300) ? null : 'http_' . $status];
    }

    $result['error'] = 'too_many_redirects';
    return $result;
}

/** The mime type of a PNG/GIF/JPEG/WebP/ICO image of a sane size, else null. SVG is refused: it can carry script. */
function zprime_icon_mime(string $bytes): ?string {
    if($bytes === '' || strlen($bytes) > ZPRIME_ICON_MAX_BYTES) return null;
    $info = @getimagesizefromstring($bytes);
    if(!is_array($info) || $info[0] < 1 || $info[1] < 1) return null;
    if($info[0] > ZPRIME_ICON_MAX_PIXELS || $info[1] > ZPRIME_ICON_MAX_PIXELS) return null;
    return match($info[2]) {
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_ICO => 'image/vnd.microsoft.icon',
        default => null,
    };
}

/**
 * Icon addresses a page declares, best first (closest to 64 px, unknown size
 * after known), SVG and mask icons skipped, then the site's /favicon.ico.
 *
 * @return string[]
 */
function zprime_favicon_candidates(string $html, string $pageUrl): array {
    $found = [];
    if(preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
        foreach($tags[0] as $order => $tag) {
            $attr = [];
            if(preg_match_all('/([a-z][a-z\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $am, PREG_SET_ORDER)) {
                foreach($am as $a) $attr[strtolower($a[1])] = $a[2] . ($a[3] ?? '') . ($a[4] ?? '');
            }
            $rel = preg_split('/\s+/', strtolower(trim($attr['rel'] ?? '')));
            if(!array_intersect($rel, ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'])) continue;
            $href = html_entity_decode(trim($attr['href'] ?? ''), ENT_QUOTES | ENT_HTML5);
            if($href === '') continue;
            $type = strtolower($attr['type'] ?? '');
            if(strpos($type, 'svg') !== false || preg_match('/\.svg(\?|#|$)/i', $href)) continue;

            $size = 0;
            if(preg_match('/(\d+)\s*x\s*(\d+)/i', $attr['sizes'] ?? '', $sm)) $size = (int)$sm[1];
            $url = stripos($href, 'data:image/') === 0 ? $href : zprime_resolve_url($href, $pageUrl);
            if($url === null) continue;
            $found[] = ['url' => $url, 'dist' => $size > 0 ? abs($size - 64) : 40, 'order' => $order];
        }
    }
    usort($found, fn($x, $y) => [$x['dist'], $x['order']] <=> [$y['dist'], $y['order']]);

    $urls = array_values(array_unique(array_column($found, 'url')));
    $p = parse_url($pageUrl);
    if($p && !empty($p['scheme']) && !empty($p['host'])) {
        $urls[] = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/favicon.ico';
    }
    return array_slice(array_values(array_unique($urls)), 0, 6);
}

/**
 * Finds and downloads the favicon of the site at $pageUrl.
 *
 * @return array{ok:true, bytes:string, mime:string, source:string}|array{ok:false, error:string}
 */
function zprime_favicon_fetch(string $pageUrl): array {
    $page = zprime_http_get($pageUrl, ZPRIME_PAGE_MAX_BYTES, true);
    if($page['error'] === 'blocked') {
        return ['ok' => false, 'error' => 'That address points at a private or local network, so the server will not fetch from it.'];
    }

    $candidates = [];
    $base = $pageUrl;
    if($page['ok']) {
        $base = $page['final_url'];
        $candidates = zprime_favicon_candidates($page['body'], $base);
    } else {
        $candidates = zprime_favicon_candidates('', $pageUrl);
    }
    $own = parse_url($pageUrl);
    if($own && !empty($own['host'])) {
        $fallback = ($own['scheme'] ?? 'https') . '://' . $own['host'] . (isset($own['port']) ? ':' . $own['port'] : '') . '/favicon.ico';
        if(!in_array($fallback, $candidates, true)) $candidates[] = $fallback;
    }

    foreach($candidates as $candidate) {
        if(stripos($candidate, 'data:image/') === 0) {
            if(!preg_match('#^data:image/[a-z+.\-]+;base64,(.+)$#is', $candidate, $dm)) continue;
            $bytes = base64_decode($dm[1], true);
        } else {
            $r = zprime_http_get($candidate, ZPRIME_ICON_MAX_BYTES);
            if(!$r['ok']) continue;
            $bytes = $r['body'];
        }
        if($bytes === false) continue;
        $mime = zprime_icon_mime($bytes);
        if($mime !== null) return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime, 'source' => $candidate];
    }

    return ['ok' => false, 'error' => $page['ok'] || $page['status'] > 0
        ? 'No icon was found on that site.'
        : 'The site could not be reached.'];
}
