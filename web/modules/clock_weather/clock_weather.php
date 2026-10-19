<?php

// Header block: live clock, date and the home temperature.
class clockWeatherModule extends moduleClass {
    function render($params = array()) {
        global $kernel;

        $home = zprime_home_config();
        $weather = zprime_weather_get($home, false);       // cache only: never block the page on the network

        $uname = $kernel->getUserName();
        $account = $uname ? usersClassEx::getUserAccount($uname) : null;

        return Renderer::render('clock_weather.zetem', [
            'home_label' => $home['label'],
            'tz' => $home['tz'],
            // First paint before the clock script runs (it takes over each second).
            'now_hm' => date('H:i'),
            'now_s' => date('s'),
            'now_date' => date('l, j F Y'),
            'temp_text' => $weather ? (round($weather['temp']) . '°' . $weather['unit']) : '--',
            'weather_label' => $weather ? $weather['label'] : '',
            'weather_url' => rel_url('/api/weather'),
            'display_name' => $account ? ($account->getname() ?: $uname) : (string)$uname,
            'logout_url' => rel_url('/logout'),
            'can_update' => zprime_can_update(),
            'update_url' => rel_url('/system/update'),
        ]);
    }
}

function register_clock_weather_module() {
    global $kernel;
    $kernel->registerModule(new clockWeatherModule(__DIR__, 'clock_weather', 'clock_weather.zetem'));
}
