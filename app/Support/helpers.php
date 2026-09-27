<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Support\Clock;

function app(): Application
{
    return Application::getInstance();
}

function bump_live(): void
{
    try {
        app()->settings()->bumpLive();
    } catch (\Throwable) {
    }
}

function config(string $key, mixed $default = null): mixed
{
    return app()->config($key, $default);
}

function env_value(string $key, mixed $default = null): mixed
{
    return \App\Core\Env::get($key, $default);
}

function e(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return app()->url($path);
}

function absolute_url(string $path = '/'): string
{
    return app()->absoluteUrl($path);
}

function asset(string $path): string
{
    return app()->url('/assets/' . ltrim($path, '/'));
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): string
{
    $old = Session::get('_old', []);
    return (string) ($old[$key] ?? $default);
}

function flash(string $key): ?string
{
    return Session::pull('flash_' . $key);
}

/** @return list<array{subject:string,url:string}> */
function mail_links(): array
{
    $links = Session::pull('flash_mail_links', []);
    if (!is_array($links)) {
        return [];
    }
    $clean = [];
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $url = (string) ($link['url'] ?? '');
        if (preg_match('#^https?://#i', $url) !== 1) {
            continue;
        }
        $clean[] = [
            'subject' => (string) ($link['subject'] ?? 'E-mail'),
            'url' => $url,
        ];
    }
    return $clean;
}

function auth(): Auth
{
    return app()->auth();
}

function current_user(): ?array
{
    return auth()->user();
}

function is_json_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, '/api/') || str_contains($accept, 'application/json');
}

function setting(string $key, mixed $default = null): mixed
{
    return app()->settings()->get($key, $default);
}

function money_format_czk(string|float|int $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' Kč';
}

function format_datetime(string $utc, string $pattern = 'd. m. Y H:i'): string
{
    return Clock::format($utc, $pattern);
}

function avatar_url(?array $user): string
{
    if ($user && !empty($user['avatar_path']) && \App\Services\AvatarService::resolveFile((string) $user['avatar_path'])) {
        return app()->url('/uploads/avatars/' . basename((string) $user['avatar_path']));
    }
    $initials = 'PF';
    if ($user) {
        $initials = mb_strtoupper(mb_substr((string) ($user['first_name'] ?? ''), 0, 1) . mb_substr((string) ($user['last_name'] ?? ''), 0, 1)) ?: 'PF';
    }
    return app()->url('/avatar/' . rawurlencode($user['public_id'] ?? 'guest') . '?i=' . rawurlencode($initials));
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Administrátor',
        default => 'Zákazník',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'active' => 'Aktivní',
        'pending' => 'Čeká',
        'blocked' => 'Blokovaný',
        'deleted' => 'Smazaný',
        'cancelled' => 'Zrušené',
        'expired' => 'Vypršelo',
        default => $status,
    };
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'active' => 'badge-ok',
        'pending' => 'badge-warn',
        'blocked', 'deleted' => 'badge-bad',
        default => 'badge-muted',
    };
}

function is_admin_user(?array $user = null): bool
{
    $user ??= current_user();
    return ($user['role'] ?? '') === 'admin';
}

function admin_view_mode(): string
{
    if (!is_admin_user()) {
        return 'user';
    }
    $mode = \App\Core\Session::get('admin_view_mode', 'admin');
    return $mode === 'user' ? 'user' : 'admin';
}

function show_admin_nav(?array $user = null): bool
{
    return is_admin_user($user) && admin_view_mode() === 'admin';
}

function show_user_nav(?array $user = null): bool
{
    if (!is_admin_user($user)) {
        return true;
    }
    return admin_view_mode() === 'user';
}

function device_label(?string $userAgent): string
{
    $ua = (string) $userAgent;
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Prohlížeč',
    };
    $os = match (true) {
        str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Mac OS'), str_contains($ua, 'Macintosh') => 'macOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Linux') => 'Linux',
        default => 'zařízení',
    };
    return $browser . ' · ' . $os;
}
