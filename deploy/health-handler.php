#!/usr/bin/env php
<?php
/**
 * ZPrime's zops health handler. See ../lib/zops/docs/HANDLERS.md.
 *
 * Checks that the database answers, the cache directory is writable, the
 * sign-in page responds, and (informational only) how long ago the home
 * weather reading was last refreshed.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';
require __DIR__ . '/../config/db.php';

use ZOps\Handler\Handler;
use ZOps\Handler\HealthCtx;

Handler::health([
    'id' => 'zprime',
    'name' => 'ZPrime',
    'check' => function (HealthCtx $h): void {
        $appDir = dirname(__DIR__);
        $cacheDir = $appDir . '/web/cache';

        $pdo = null;
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');
            $h->ok('db.connect', 'MySQL connects');
        } catch (Throwable $e) {
            $h->fail('db.connect', 'MySQL connects', $e->getMessage());
        }

        if (!is_dir($cacheDir)) {
            $h->warn('fs.cache.exists', 'web/cache/ exists', 'directory does not exist yet');
        } else {
            $h->writeProbe($cacheDir)
                ? $h->ok('fs.cache.write', 'web/cache/ writable')
                : $h->fail('fs.cache.write', 'web/cache/ writable', 'write probe failed');
        }

        // The sign-in page is the one page that answers without a session.
        $baseUrl = rtrim(getenv('ZPRIME_HEALTH_BASE_URL') ?: 'http://127.0.0.1', '/');
        $resp = $h->httpGet($baseUrl . '/login');
        ($resp['code'] === 200 && stripos($resp['body'], 'name="username"') !== false)
            ? $h->ok('route.login', 'Sign-in page responds')
            : $h->warn('route.login', 'Sign-in page responds', "HTTP {$resp['code']}" . ($resp['error'] ? " ({$resp['error']})" : ''));

        $wx = $cacheDir . '/zprime-weather.json';
        if (is_file($wx)) {
            $h->info('weather_reading_age_min', (int) round((time() - (int) filemtime($wx)) / 60));
        }

        if ($pdo !== null) {
            $h->info('users_total', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $h->info('links_total', (int) $pdo->query('SELECT COUNT(*) FROM dash_links')->fetchColumn());
            $h->info('places_total', (int) $pdo->query('SELECT COUNT(*) FROM dash_places')->fetchColumn());
        }
    },
]);
