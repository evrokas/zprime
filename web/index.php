<?php

// Load the database parameters, unless they are already defined: the test
// suite's router (tests/lib/router.php) defines the throwaway test database
// first, so a test server never reads this app's real credentials.
if(!defined('DB_NAME')) {
    include_once(__DIR__ . "/../config/db.php");
}

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/dash_lib.php');
require_once(__DIR__ . '/dash.php');

// A session lasts until the browser closes; staying signed in across
// restarts and days is the separate "remember me" cookie (see
// remember_me_days in config/site.info.yaml), same as every ZeusFW app.
ini_set('session.gc_maxlifetime', 3600);

$kernel = new Kernel($_SERVER, "../config");

$kernel->boot([
    'csrf_login' => true,
    'csrf_webforms' => true,
    'login_lockout' => true,
    'login_redirect' => '/login',
    'default_language' => 'en',
]);
