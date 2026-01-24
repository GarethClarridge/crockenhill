<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. A "local" driver, as well as a variety of cloud
    | based drivers are available for your choosing. Just store away!
    |
    | Supported: "local", "s3", "rackspace"
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */

    'cloud' => 's3',

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_KEY'),
            'secret' => env('AWS_SECRET'),
            'region' => env('AWS_REGION'),
            'bucket' => env('AWS_BUCKET'),
        ],

        'public_images' => [
            'driver' => 'local',
            'root' => public_path(),
            'url' => env('APP_URL').'/',
            'visibility' => 'public',
        ],

        'do_spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY', env('DO_SPACES_ACCESS_KEY_ID')),
            'secret' => env('DO_SPACES_SECRET', env('DO_SPACES_SECRET_ACCESS_KEY')),
            'region' => env('DO_SPACES_REGION', env('DO_SPACES_DEFAULT_REGION', 'nyc3')),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT', 'https://nyc3.digitaloceanspaces.com'),
            'use_path_style_endpoint' => false,
            'throw' => false,
            'visibility' => 'public',
            'bucket_endpoint' => true,
            'cdn_endpoint' => env('DO_SPACES_CDN_ENDPOINT'),
        ],

    ],

];
