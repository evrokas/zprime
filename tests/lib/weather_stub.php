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
echo json_encode(['current' => ['temperature_2m' => 21.4, 'weather_code' => 2, 'is_day' => 1]]);
