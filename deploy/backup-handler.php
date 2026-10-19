#!/usr/bin/env php
<?php
/**
 * ZPrime's zops backup handler. See ../lib/zops/docs/HANDLERS.md.
 *
 * Elements shipped:
 *   - db      mysqldump of the ZPrime database (--single-transaction):
 *             accounts, saved websites and places
 *   - config  config/db.php and config/site.info.yaml
 *
 * Nothing under web/files/ is backed up: ZPrime stores no uploads, and
 * web/cache/ holds only the cached weather reading and compiled templates.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';
require __DIR__ . '/../config/db.php';

use ZOps\Handler\ConfigBundle;
use ZOps\Handler\Ctx;
use ZOps\Handler\Handler;
use ZOps\Handler\MysqlDump;

Handler::backup([
    'id' => 'zprime',
    'name' => 'ZPrime',
    'prepare' => function (Ctx $c): void {
        $appDir = dirname(__DIR__);

        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $c->setStats([
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'links' => (int) $pdo->query('SELECT COUNT(*) FROM dash_links')->fetchColumn(),
            'places' => (int) $pdo->query('SELECT COUNT(*) FROM dash_places')->fetchColumn(),
        ]);

        $c->add(MysqlDump::zip($c, 'db', ['host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASS, 'db' => DB_NAME]));

        $configPaths = [$appDir . '/config/db.php'];
        if (is_file($appDir . '/config/site.info.yaml')) {
            $configPaths[] = $appDir . '/config/site.info.yaml';
        }
        $c->add(ConfigBundle::zip($c, 'config', $configPaths));
    },
]);
