<?php

declare(strict_types=1);

return [
    'session' => [
        'name' => env_value('SESSION_NAME', 'privofit_session'),
        'lifetime' => (int) env_value('SESSION_LIFETIME', 20160),
        'idle_minutes' => (int) env_value('SESSION_IDLE_MINUTES', 20),
        'secure' => (bool) env_value('SESSION_SECURE', false),
        'http_only' => (bool) env_value('SESSION_HTTP_ONLY', true),
        'same_site' => env_value('SESSION_SAME_SITE', 'Lax'),
        'remember_days' => (int) env_value('REMEMBER_ME_DAYS', 30),
        'mfa_trust_days' => (int) env_value('MFA_TRUST_DAYS', 30),
        'mfa_trust_cookie' => 'privofit_mfa',
    ],
    'login' => [
        'max_attempts_account' => 5,
        'max_attempts_ip' => 20,
        'window_minutes' => 15,
        'lock_minutes' => 15,
    ],
    'rate_limits' => [
        'register' => ['limit' => 5, 'minutes' => 60],
        'forgot' => ['limit' => 3, 'minutes' => 60],
        'contact' => ['limit' => 5, 'minutes' => 60],
        'access_open' => ['limit' => 8, 'minutes' => 5],
        'username_change' => ['limit' => 1, 'minutes' => 20160],
        'api' => ['limit' => 120, 'minutes' => 1],
    ],
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com" "https://hooks.stripe.com" "https://pay.google.com")',
    ],
    'hibp_enabled' => (bool) env_value('HIBP_ENABLED', true),
    'mfa_required_roles' => ['admin'],
];
