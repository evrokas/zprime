#!/usr/bin/env php
<?php
/*
 * Creates ZPrime's owner role and an account, or resets an account's
 * password. CLI only. Safe to re-run: an existing role/account is reused.
 *
 *   php bin/setup.php --user=evangelos --name="Evangelos Rokas" --email=me@example.com
 *
 * The password is asked for without echo. For automation (tests) it can be
 * given in the ZPRIME_PASSWORD environment variable instead; it is never
 * accepted as a command-line argument, where it would end up in shell
 * history and the process list.
 *
 * The account gets the `owner` role (is_superuser), which also opens the
 * /admin/users screen for adding more accounts later.
 *
 * Targets config/db.php; set ZPRIME_DB_CONFIG to use another file (the
 * test suite points it at config/db.test.php).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$opt = getopt('', ['user:', 'name:', 'email:']);
$uname = (string)($opt['user'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{2,32}$/', $uname)) {
    fwrite(STDERR, "Usage: php bin/setup.php --user=<2-32 letters/digits/._-> [--name=\"Full Name\"] [--email=you@example.com]\n");
    exit(1);
}
$name = (string)($opt['name'] ?? $uname);
$email = (string)($opt['email'] ?? ($uname . '@example.invalid'));

$password = getenv('ZPRIME_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDERR, "Password for $uname: ");
    $tty = stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $password = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) { shell_exec('stty echo'); fwrite(STDERR, "\n"); }
}
if (strlen($password) < 10) {
    fwrite(STDERR, "The password must be at least 10 characters.\n");
    exit(1);
}

define('__APPDIR__', dirname(__DIR__));
$configPath = getenv('ZPRIME_DB_CONFIG') ?: (__APPDIR__ . '/config/db.php');
if (!is_file($configPath)) {
    fwrite(STDERR, "Database config not found: $configPath (run fw/bin/init.sh first)\n");
    exit(1);
}
require_once $configPath;
if (!defined('__FWDIR__')) define('__FWDIR__', __APPDIR__ . '/web/core');
if (!is_dir(__FWDIR__)) {
    fwrite(STDERR, __FWDIR__ . " not found -- link web/core to fw/core first.\n");
    exit(1);
}

// web/user_classes.php reads classes/bootstrap_classes.php relative to the
// current directory. Generated entity class files may print stray bytes
// when included, so keep them out of this script's output.
chdir(__APPDIR__ . '/web');
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
require_once __APPDIR__ . '/web/dash_lib.php';
ob_end_clean();

$kernel = new Kernel(['MAKER_INVOKE' => true, 'PHP_SELF' => __FILE__, 'SCRIPT_FILENAME' => __FILE__], __APPDIR__ . '/config');
$db = dbConnection::getConnection();

// owner role
$role = rolesClassEx::sgetByName('owner');
if (!$role) {
    $role = new rolesClass([
        'guid' => guid(), 'cuser' => 'setup', 'name' => 'owner',
        'label' => 'Owner (full access)', 'is_superuser' => 1,
    ]);
    $role->insert();
    $role = rolesClassEx::sgetByName('owner');
    echo "Created role: owner\n";
}

// account
$st = $db->prepare('SELECT * FROM users WHERE uname = :u');
$st->execute([':u' => $uname]);
$row = $st->fetch();
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($row) {
    $db->prepare('UPDATE users SET upass = :p, name = :n, email = :e, active = 1, expired = 0, wrongpasscount = 0 WHERE id = :id')
       ->execute([':p' => $hash, ':n' => $name, ':e' => $email, ':id' => $row['id']]);
    $userId = (int)$row['id'];
    echo "Updated account: $uname (password reset, unlocked)\n";
} else {
    $db->prepare('INSERT INTO users (name, email, uname, upass, active, expired, wrongpasscount, roles) VALUES (:n, :e, :u, :p, 1, 0, 0, :r)')
       ->execute([':n' => $name, ':e' => $email, ':u' => $uname, ':p' => $hash, ':r' => 'owner']);
    $userId = (int)$db->lastInsertId();
    echo "Created account: $uname\n";
}

user_rolesClassEx::assignRole($userId, (int)$role->getid(), 'setup');
echo "Role owner assigned to $uname.\n";
