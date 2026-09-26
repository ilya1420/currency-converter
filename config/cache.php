<?php

// Framework maintenance commands need a cache driver; no application data is cached here.
return [
    'default' => 'array',
    'stores' => ['array' => ['driver' => 'array']],
];
