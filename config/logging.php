<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [
    'default' => 'single',
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'single' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => storage_path('logs/laravel.log')],
            'level' => env('LOG_LEVEL', 'warning'),
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];
