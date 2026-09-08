<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Front controller
|--------------------------------------------------------------------------
|
| The preferred Hostinger arrangement points the domain's document root at
| this directory, so the application, .env and storage all stay outside the
| web root without this file changing (Article 10). When the host refuses to
| move the document root, deploy/public_html-index.php is copied into
| public_html instead and this file is never reached.
|
*/

if (is_file($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
