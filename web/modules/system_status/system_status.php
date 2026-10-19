<?php

// "System" block: live status of the machines watched by sysmonitor. Renders an empty shell; the
// numbers are fetched by js/sysmon.js so a slow or dead agent can never hold up the page.
class systemStatusModule extends moduleClass {
    function render($params = array()) {
        $cfg = zprime_sysmon_config();
        if(!zprime_sysmon_hosts($cfg) || !zprime_sysmon_allowed($cfg)) return '';

        return Renderer::render('system_status.zetem', [
            'data_url' => rel_url('/api/sysmon'),
            'series_base' => rel_url('/api/sysmon'),
        ]);
    }
}

function register_system_status_module() {
    global $kernel;
    $kernel->registerModule(new systemStatusModule(__DIR__, 'system_status', 'system_status.zetem'));
}
