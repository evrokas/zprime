<?php
/* Stand-in for Open-Meteo, run by WeatherStub (functional/weather.php's
 * helper) under `php -S`. Counts every request in $STUB_DIR/count and
 * answers according to $STUB_DIR/mode ("ok" or "fail"). */
$dir = getenv('ZPRIME_STUB_DIR');
$count = (int)@file_get_contents("$dir/count");
file_put_contents("$dir/count", (string)($count + 1));
$mode = trim((string)@file_get_contents("$dir/mode"));

if ($mode === 'fail') {
    http_response_code(500);
    echo 'down';
    exit;
}
header('Content-Type: application/json');
if (isset($_GET['daily'])) {                         // forecast request: 7 days, local time = UTC+2
    $off = 7200;
    $midnight = intdiv(time() + $off, 86400) * 86400;
    $codes = [0, 2, 61, 3, 71, 95, 45];
    $h = ['time' => [], 'temperature_2m' => [], 'weather_code' => [], 'precipitation_probability' => []];
    for ($i = 0; $i < 168; $i++) {
        $h['time'][] = gmdate('Y-m-d\TH:00', $midnight + $i * 3600);
        $h['temperature_2m'][] = 10 + ($i % 24) / 2 + intdiv($i, 24);
        $h['weather_code'][] = $codes[intdiv($i, 24) % 7];
        $h['precipitation_probability'][] = ($i * 7) % 100;
    }
    $d = ['time' => [], 'weather_code' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [],
          'precipitation_probability_max' => [], 'precipitation_sum' => [], 'wind_speed_10m_max' => []];
    for ($i = 0; $i < 7; $i++) {
        $d['time'][] = gmdate('Y-m-d', $midnight + $i * 86400);
        $d['weather_code'][] = $codes[$i];
        $d['temperature_2m_max'][] = 20.4 + $i;
        $d['temperature_2m_min'][] = 11.6 + $i;
        $d['precipitation_probability_max'][] = $i * 10;
        $d['precipitation_sum'][] = $i === 2 ? 4.26 : 0;
        $d['wind_speed_10m_max'][] = 18.4;
    }
    echo json_encode(['utc_offset_seconds' => $off, 'hourly' => $h, 'daily' => $d]);
    exit;
}
echo json_encode(['current' => ['temperature_2m' => 21.4, 'weather_code' => 2, 'is_day' => 1]]);
