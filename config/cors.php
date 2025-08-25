<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',      // Local development
        'http://127.0.0.1:3000',      // Local development  
        'https://team-board-frontend-seven.vercel.app',  // Main production URL
        'https://team-board-frontend-cvcdw02xq-hadis-projects-3e6c26c1.vercel.app', // Deployment URL
    ],
    
    'allowed_origins_patterns' => [
        '/^https:\/\/team-board-frontend.*\.vercel\.app$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

    

];
