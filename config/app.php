<?php

return [
    'name' => env('APP_NAME', 'Конвертер'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'ru',
    'fallback_locale' => 'ru',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'maintenance' => ['driver' => 'file'],
];
