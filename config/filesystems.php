<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default disk
    |--------------------------------------------------------------------------
    |
    | Everything a user uploads - assignment files, project deliverables,
    | avatars, resources - lands on a private disk that sits OUTSIDE the web
    | root. There is no public URL to guess: access always goes through a
    | permission check and then a temporary signed URL valid for 15 minutes
    | (PRD §12.5, art. 22).
    |
    | `local` and `private` deliberately point at the same directory, so a
    | forgotten disk name cannot quietly publish a private file. `local` is
    | kept because it is what the framework and the deployment guide name.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'private'),

    'disks' => [

        /*
        | `serve` stays FALSE on every private disk. With it on, the framework
        | publishes GET and PUT routes at /storage/{path} for each of these
        | disks (Illuminate\Filesystem\FilesystemServiceProvider::serveFiles) -
        | an endpoint with no `auth` middleware, no FormRequest and no Policy,
        | which writes whatever body it is given straight onto the disk. That
        | is the exact opposite of "the application is outside the web root and
        | every read goes through a permission check first", and art. 5 allows
        | no state-changing endpoint without all three guards. The platform
        | mints its own temporary signed links to its OWN named download routes
        | (App\Services\Storage\PrivateFileService::temporaryUrl), which are
        | authenticated and policy-checked, so nothing needs these.
        */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
        | Generated artefacts that are still private: rendered certificate
        | PDFs and exported spreadsheets. Separate from uploads so a retention
        | policy can treat them differently once D-12 is decided.
        */
        'generated' => [
            'driver' => 'local',
            'root' => storage_path('app/generated'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
        | The only web-readable disk. Reserved for assets the platform itself
        | publishes - never for anything a user uploaded.
        */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic links
    |--------------------------------------------------------------------------
    |
    | Created by `php artisan storage:link`. On Hostinger the link has to be
    | recreated after every release because the web root is a separate
    | directory - see deploy/README.md.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
