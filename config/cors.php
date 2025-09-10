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
    'https://team-board-frontend-murex.vercel.app', // ✅ Your new Vercel domain
    'https://app.boardflow.space',  // ✅ Your new custom domain
    'https://teamboard-0vf2.onrender.com', // ✅ Temporary - old Render URL
],
'allowed_origins_patterns' => [
    '/^https:\/\/team-board-frontend.*\.vercel\.app$/',
    '/^https:\/\/.*\.boardflow\.space$/',  // ✅ Allow all subdomains of boardflow.space
],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

    

];
