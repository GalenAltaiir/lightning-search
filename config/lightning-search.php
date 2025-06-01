<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search Service Configuration
    |--------------------------------------------------------------------------
    */

    // Go service configuration
    'service' => [
        'host' => env('LIGHTNING_SEARCH_HOST', '127.0.0.1'),
        'port' => env('LIGHTNING_SEARCH_PORT', 8081),
        'timeout' => env('LIGHTNING_SEARCH_TIMEOUT', 5), // seconds
    ],

    // Database configuration (will use Laravel's database config by default)
    'database' => [
        'connection' => env('LIGHTNING_SEARCH_DB_CONNECTION', env('DB_CONNECTION')),
        'host' => env('LIGHTNING_SEARCH_DB_HOST', env('DB_HOST')),
        'port' => env('LIGHTNING_SEARCH_DB_PORT', env('DB_PORT')),
        'database' => env('LIGHTNING_SEARCH_DB_DATABASE', env('DB_DATABASE')),
        'username' => env('LIGHTNING_SEARCH_DB_USERNAME', env('DB_USERNAME')),
        'password' => env('LIGHTNING_SEARCH_DB_PASSWORD', env('DB_PASSWORD')),
    ],

    // Performance tuning
    'performance' => [
        'cpu_cores' => env('LIGHTNING_SEARCH_CPU_CORES', 1),
        'max_connections' => env('LIGHTNING_SEARCH_MAX_CONNECTIONS', 10),
        'cache_duration' => env('LIGHTNING_SEARCH_CACHE_DURATION', 300), // seconds
        'result_limit' => env('LIGHTNING_SEARCH_RESULT_LIMIT', 1000),
    ],

    // Searchable models configuration
    'models' => [
        // Example:
        // \App\Models\User::class => [
        //     'searchable_fields' => ['name', 'email'],
        //     'index_fields' => ['id', 'name', 'email', 'created_at'],
        //     'table' => 'users', // optional, will be inferred from model
        // ],
    ],

    // Search modes
    'modes' => [
        'default' => env('LIGHTNING_SEARCH_DEFAULT_MODE', 'go'), // 'go' or 'eloquent'
        'fallback' => env('LIGHTNING_SEARCH_FALLBACK_MODE', 'eloquent'),
    ],
];
