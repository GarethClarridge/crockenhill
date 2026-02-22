<?php

$appUrl = env('APP_URL', 'https://crockenhill.org');

$allowedOrigins = [
    $appUrl,
    str_replace('https://', 'https://www.', $appUrl),
];

if (env('APP_ENV') === 'local') {
    $allowedOrigins = array_merge($allowedOrigins, [
        'http://localhost:3000',
        'http://localhost:8000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:8000',
    ]);
}

$allowedOrigins = array_values(array_unique(array_filter($allowedOrigins, fn (mixed $origin): bool => is_string($origin) && $origin !== '')));

$allowedOriginPatterns = array_map(
    fn (string $origin): string => '#^'.preg_quote($origin, '#').'\z#u',
    $allowedOrigins
);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,
];
