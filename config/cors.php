<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines which cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Explicitly allow the frontend origin(s) used in local development.
    'allowed_origins' => [
        'http://localhost:8002',
        'http://127.0.0.1:8002',
    ],

    'allowed_origins_patterns' => [],

    // Allow all headers
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // IMPORTANT: allow credentials so cookies can be used for session auth
    'supports_credentials' => true,
];
