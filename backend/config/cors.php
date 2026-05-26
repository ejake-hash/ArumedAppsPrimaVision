<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    // 1. TULISKAN PORT VUE KAMU SECARA SPESIFIK (JANGAN PAKAI BINTANG)
    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // 2. INI KUNCI UTAMANYA AGAR SESI LOGIN (COOKIE/TOKEN) DISIMPAN BROWSER
    'supports_credentials' => true, 

];