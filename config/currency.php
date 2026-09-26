<?php

return [
    'nbrb' => [
        'base_url' => env('NBRB_API_BASE_URL', 'https://api.nbrb.by/exrates'),
        'rate_ttl_seconds' => (int) env('NBRB_RATE_TTL_SECONDS', 21600),
        'max_stale_age_seconds' => (int) env('NBRB_MAX_STALE_AGE_SECONDS', 604800),
    ],
    'kraken' => ['rate_ttl_seconds' => (int) env('KRAKEN_RATE_TTL_SECONDS', 60), 'max_stale_age_seconds' => (int) env('KRAKEN_MAX_STALE_AGE_SECONDS', 86400)],
    'http' => [
        'connect_timeout' => 3,
        'timeout' => 5,
        'retry_times' => 2,
        'retry_delay_ms' => 200,
    ],
];
