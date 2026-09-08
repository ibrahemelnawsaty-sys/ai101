<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View storage
    |--------------------------------------------------------------------------
    |
    | Compiled Blade templates are cached under storage/framework/views, which
    | sits outside the web root. On shared hosting the directory must be
    | writable by the PHP user and is cleared on every release.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')),
    ),

];
