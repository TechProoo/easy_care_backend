<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN', 'Idempotency-Key'],
    'exposed_headers' => ['Retry-After', 'Idempotency-Replayed'],
    'max_age' => 600,
    'supports_credentials' => true,
];
