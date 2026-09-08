<?php

/**
 * Athar Platform - web-root shim for Hostinger shared hosting.
 *
 * Deploy target: $HOME/public_html/index.php  (copy this file, rename it to index.php)
 * Application:   $HOME/athar-app              (OUTSIDE the web root)
 *
 * Use this file only when the host will not let you point the domain's document root at
 * the application's own public/ directory. Pointing the document root directly at
 * athar-app/public is the preferred arrangement - see deploy/README.md section 9, Option A.
 *
 * The ONLY line you may edit here is ATHAR_APP_BASE.
 *
 * @see CONSTITUTION.md Article 10 (shared hosting: the app lives outside the web root)
 * @see PROJECT-CONTRACT.md section 11
 */
define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Application base path
|--------------------------------------------------------------------------
|
| Absolute path to the application root on the server. It must NOT be inside
| the web root. dirname(__DIR__) resolves to the account home when this file
| sits directly in public_html; adjust the trailing segment to match the
| directory you extracted the release into.
|
| If your document root is nested (for example
| $HOME/domains/ai.wareed.vip/public_html), dirname(__DIR__) is the
| domain directory, not the home directory. In that case replace the whole
| expression with the literal absolute path, e.g.:
|
|     define('ATHAR_APP_BASE', '/home/u123456789/athar-app');
|
*/

define('ATHAR_APP_BASE', dirname(__DIR__).'/athar-app');

/*
|--------------------------------------------------------------------------
| Fail closed
|--------------------------------------------------------------------------
|
| If the application cannot be located, do not emit a PHP warning containing
| server paths and do not fall through to a directory listing. Return a plain
| 503 and log it. Article 7: when in doubt, refuse.
|
*/

if (! is_file(ATHAR_APP_BASE.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Retry-After: 120');
    error_log('[athar] shim: application not found at '.ATHAR_APP_BASE);

    exit('Service Unavailable');
}

/*
|--------------------------------------------------------------------------
| Maintenance mode
|--------------------------------------------------------------------------
|
| php artisan down writes this file. Serving it here means `artisan down`
| works exactly as it does in a standard deployment.
|
*/

if (is_file($maintenance = ATHAR_APP_BASE.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Composer autoloader
|--------------------------------------------------------------------------
*/

require ATHAR_APP_BASE.'/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Bootstrap and handle the request
|--------------------------------------------------------------------------
|
| usePublicPath() is what makes this shim work: without it public_path()
| would resolve to ATHAR_APP_BASE/public, while the browser is actually being
| served from this directory. The Vite manifest is read through public_path(),
| so public/build/ must be published into THIS directory on every deploy
| (deploy/README.md section 9, Option B, step 3).
|
*/

/** @var Illuminate\Foundation\Application $app */
$app = require_once ATHAR_APP_BASE.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Illuminate\Http\Request::capture());
