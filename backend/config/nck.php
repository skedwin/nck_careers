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
    'ai_enabled' => (bool) env('AI_ENABLED', false),
];
