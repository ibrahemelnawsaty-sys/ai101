<?php
$base = 'c:/Users/b.maher/Downloads/wesal/LARAVEL';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$m = new App\Models\FinalProject();
print_r($m->getCasts());
