<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
    'frontend_urls' => array_values(array_filter(array_unique(array_merge(
        [
            env('FRONTEND_URL', 'http://127.0.0.1:5173'),
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ],
        array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URLS', '')))),
    )))),
    'auth_dev_login' => (bool) env('AUTH_DEV_LOGIN', false),
    'mailbox' => env('MICROSOFT_MAILBOX', 'careers@nckenya.go.ke'),
    'graph_mock_mode' => (bool) env('GRAPH_MOCK_MODE', true),
    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'mock'),
        'api_key' => env('AI_API_KEY'),
        'endpoint' => env('AI_ENDPOINT'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_version' => env('AI_API_VERSION', '2024-06-01'),
        'confidence_threshold' => (float) env('AI_CONFIDENCE_THRESHOLD', 0.7),
        'timeout' => (int) env('AI_HTTP_TIMEOUT', 45),
    ],
];
