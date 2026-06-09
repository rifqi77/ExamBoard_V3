<?php

return [
    // Dual-purpose secret: HS256 JWT signing AND root of bespoke AES-256-GCM
    // at-rest encryption (parity with the original crypto-secrets.ts).
    'session_secret' => env('SESSION_SECRET', ''),

    'cookie_name' => env('SESSION_COOKIE_NAME', 'secure-exam-session'),
    // Clamped 1..30, default 5 (matches original).
    'cookie_days' => max(1, min(30, (int) env('SESSION_COOKIE_DAYS', 5))),

    'login_ip_rate_limit' => (int) env('LOGIN_IP_RATE_LIMIT', 1000),

    // Server-only AI provider keys (fallback when not stored in app_config_ai).
    'ai' => [
        'gemini_key' => env('GEMINI_API_KEY', ''),
        'anthropic_key' => env('ANTHROPIC_API_KEY', ''),
        'openai_key' => env('OPENAI_API_KEY', ''),
    ],
];
