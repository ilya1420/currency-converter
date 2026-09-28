<?php

return [
    // Currency catalog and daily-change cache must never require the optional database cache table.
    'default' => env('CURRENCY_CACHE_STORE', 'file'),
    'stores' => [
        'array' => ['driver' => 'array'],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
    ],
];
