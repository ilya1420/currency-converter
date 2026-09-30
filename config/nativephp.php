<?php

/*
 * NativePHP publishes its complete default configuration from the package.
 * Keeping the security deltas here avoids copying a generated 500-line file
 * and makes those deltas survive NativePHP updates.
 */
$nativeConfig = base_path('vendor/nativephp/mobile/config/nativephp.php');

if (! is_file($nativeConfig)) {
    return [];
}

$config = require $nativeConfig;

$config['cleanup_env_keys'] = [
    ...$config['cleanup_env_keys'],
    'APP_KEY',
    'ANDROID_KEYSTORE_FILE',
    'ANDROID_KEYSTORE_PASSWORD',
    'ANDROID_KEY_ALIAS',
    'ANDROID_KEY_PASSWORD',
];

$config['cleanup_exclude_files'] = [
    ...$config['cleanup_exclude_files'],
    // Build artifacts must never be bundled into the next application build.
    'builds',
    '.phpunit.result.cache',
    '.npmrc',
    'package-lock.json',
    'tests',
    'credentials',
    'AGENTS.md',
    '.agents',
    '.claude',
    '.codex',
    '.junie',
    '.mcp.json',
    'boost.json',
];

return $config;
