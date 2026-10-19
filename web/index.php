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

// The Active / Expired boxes on /admin/users must mean something: an account that is not active, or has
// expired, cannot sign in with its password. (Accounts made by bin/setup.php or the Add form with
// Active ticked are fine; see also zeusfw_app_boot() in dash.php for sessions that already exist.)
LoginSecurityClass::enableAccountStatusEnforcement();

$kernel->boot([
    'csrf_login' => true,
    'csrf_webforms' => true,
    'login_lockout' => true,
    'login_redirect' => '/login',
    'default_language' => 'en',
]);
