<?php

// "Forecast" block: next 24 hours and 7 days for the home location. An empty shell; js/forecast.js fetches
// /api/forecast, so a slow weather service never holds up the page.
class forecastModule extends moduleClass {
    function render($params = array()) {
        if(!SecurityClass::userLoggedIn()) return '';
        $home = zprime_home_config();
        return Renderer::render('forecast.zetem', [
            'data_url' => rel_url('/api/forecast'),
            'place' => $home['label'],
        ]);
    }
}

function register_forecast_module() {
    global $kernel;
    $kernel->registerModule(new forecastModule(__DIR__, 'forecast', 'forecast.zetem'));
}
