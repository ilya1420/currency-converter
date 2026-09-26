<?php

// The converter stores rates in SQLite and does not expose file storage.
return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
        ],
    ],
    'links' => [],
];
