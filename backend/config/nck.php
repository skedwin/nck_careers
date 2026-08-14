<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
    'auth_dev_login' => (bool) env('AUTH_DEV_LOGIN', false),
    'mailbox' => env('MICROSOFT_MAILBOX', 'careers@nckenya.go.ke'),
    'graph_mock_mode' => (bool) env('GRAPH_MOCK_MODE', true),
    'ai_enabled' => (bool) env('AI_ENABLED', false),
];
