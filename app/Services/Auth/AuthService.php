<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\AvatarService;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Services\MailService;
use App\Services\PasswordBreachService;
use App\Services\PhoneService;
use App\Support\Clock;
use OTPHP\TOTP;

final class AuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly MailService $mail,
        private readonly PhoneService $phone,
        private readonly PasswordBreachService $breaches,
        private readonly AvatarService $avatars,
        private readonly AuditService $audit,
        private readonly RateLimiter $limiter,
    ) {
    }

    private bool $issueMfaTrust = false;

    public static function make(Database $db): self
    {
        return new self(
            $db,
            new MailService($db),
            new PhoneService(),
            new PasswordBreachService(),
            new AvatarService($db),
            new AuditService($db),
            new RateLimiter($db),
        );
    }

    public function register(array $input, Request $request, ?array $file = null): array
    {
        if ((string) env_value('APP_ENV', 'local') !== 'testing'
            && !$this->limiter->attempt('register', $request->ip(), 5, 60)) {
            throw new HttpException(429, 'Příliš mnoho pokusů o registraci. Zkuste to později.');
        }

        $email = $this->normalizeEmail((string) ($input['email'] ?? ''));
        $username = $this->normalizeUsername((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $phone = $this->phone->normalize(isset($input['phone']) ? (string) $input['phone'] : null);

        $validator = new Validator();
        $validator->require($input, ['username', 'first_name', 'last_name', 'email', 'password', 'password_confirmation']);
        $validator->username('username', $username);
        $validator->email('email', $email);
        $validator->minLength('first_name', $input['first_name'] ?? '', 1)->maxLength('first_name', $input['first_name'] ?? '', 100);
        $validator->minLength('last_name', $input['last_name'] ?? '', 1)->maxLength('last_name', $input['last_name'] ?? '', 100);
        $validator->minLength('password', $password, (int) config('app.password_min_length', 12));
        $validator->confirmed('password', $password, $input['password_confirmation'] ?? '');
        $validator->accepted('terms', $input['terms'] ?? null, 'Musíte souhlasit s obchodními podmínkami.');
        $validator->accepted('privacy', $input['privacy'] ?? null, 'Musíte potvrdit seznámení se zásadami ochrany osobních údajů.');

        if (in_array($username, (array) config('app.reserved_usernames', []), true)) {
            $validator->add('username', 'Toto uživatelské jméno není k dispozici.');
        }
        if ($this->db->fetch('SELECT id FROM users WHERE username = :u AND deleted_at IS NULL', ['u' => $username])) {
            $validator->add('username', 'Toto uživatelské jméno je již obsazené.');
        }
        if ($this->db->fetch('SELECT id FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => $email])) {
            $validator->add('email', 'Tento e-mail nelze použít.');
        }
        if (!empty($input['phone']) && $phone === null) {
            $validator->add('phone', 'Zadejte platné telefonní číslo.');
        }
        if ($password !== '' && $this->breaches->isCompromised($password)) {
            $validator->add('password', 'Toto heslo je v seznamech uniklých hesel. Zvolte jiné.');
        }
        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $validator->first() ?? 'Zkontrolujte zadané údaje.');
        }

        $now = Clock::utc();
        $publicId = Crypto::uuid();
        $userId = (int) $this->db->insert('users', [
            'public_id' => $publicId,
            'email' => $email,
            'username' => $username,
            'password_hash' => Crypto::hashPassword($password),
            'first_name' => trim((string) $input['first_name']),
            'last_name' => trim((string) $input['last_name']),
            'phone' => $phone,
            'role' => 'user',
            'plan' => 'free',
            'status' => 'pending',
            'locale' => 'cs-CZ',
            'timezone' => 'Europe/Prague',
            'default_currency' => 'CZK',
            'terms_accepted_at' => $now,
            'privacy_accepted_at' => $now,
            'marketing_opt_in' => !empty($input['marketing']) ? 1 : 0,
            'marketing_opt_in_at' => !empty($input['marketing']) ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $role = $this->db->fetch('SELECT id FROM roles WHERE slug = :s', ['s' => 'user']);
        if ($role) {
            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => (int) $role['id'],
                'assigned_at' => $now,
            ]);
        }
        $this->db->insert('notification_preferences', [
            'user_id' => $userId,
            'email_marketing' => !empty($input['marketing']) ? 1 : 0,
        ]);

        $user = $this->findById($userId);
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $crop = [
                'x' => $input['avatar_x'] ?? null,
                'y' => $input['avatar_y'] ?? null,
                'size' => $input['avatar_size'] ?? null,
            ];
            $this->avatars->storeFromUpload($user, $file, array_filter($crop, static fn ($v) => $v !== null && $v !== ''));
            $user = $this->findById($userId);
        }

        $this->sendVerification($user);
        $this->mail->queue('welcome', $email, [
            'subject' => 'Vítejte v PRIVOFIT',
            'first_name' => $user['first_name'],
        ], $userId);
        $this->notifyAdminsRegistered($user);

        return $user;
    }

    public function login(string $identifier, string $password, Request $request, bool $remember = false, ?string $totp = null): array
    {
        $this->issueMfaTrust = false;
        $user = $this->verifyCredentials($identifier, $password, $request, $totp);
        $sessionUser = $this->establishWebSession($user, $request, $remember);
        if ($this->issueMfaTrust) {
            $this->rememberBrowser((int) $user['id'], $request);
            $this->issueMfaTrust = false;
        }
        return $sessionUser;
    }

    /** @param array{sub:string,email:string,given_name:string,family_name:string,name:string} $profile */
    public function loginWithGoogle(array $profile, Request $request): array
    {
        return $this->finishOAuthLogin('google', $profile, $request);
    }

    /** @param array{sub:string,email:string,given_name:string,family_name:string,name:string} $profile */
    public function loginWithApple(array $profile, Request $request): array
    {
        return $this->finishOAuthLogin('apple', $profile, $request);
    }

    /** @param array{sub:string,email:string,given_name:string,family_name:string,name:string} $profile */
    private function finishOAuthLogin(string $provider, array $profile, Request $request): array
    {
        $user = $this->findOrCreateOAuthUser($provider, $profile);
        if (in_array($user['status'], ['blocked', 'deleted'], true) || !empty($user['deleted_at'])) {
            throw new HttpException(403, 'Tento účet je zablokovaný.');
        }
        if ((int) $user['mfa_enabled'] === 1 && !$this->browserIsTrusted((int) $user['id'])) {
            throw new MfaRequiredException($user);
        }
        return $this->establishWebSession($user, $request, false);
    }

    /** @param array{sub:string,email:string,given_name:string,family_name:string,name:string} $profile */
    private function findOrCreateOAuthUser(string $provider, array $profile): array
    {
        $email = $this->normalizeEmail($profile['email']);
        $sub = $profile['sub'];
        $other = $provider === 'google' ? 'apple' : 'google';
        $linked = $this->db->fetch(
            'SELECT * FROM users WHERE oauth_provider = :p AND oauth_uid = :u AND deleted_at IS NULL',
            ['p' => $provider, 'u' => $sub]
        );
        if ($linked) {
            return $linked;
        }
        if ($email === '') {
            throw new HttpException(403, 'Účet neposlal e-mail. Při souhlasu ho nech sdílet.');
        }

        $existing = $this->db->fetch('SELECT * FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => $email]);
        if ($existing) {
            if (in_array($existing['status'], ['blocked', 'deleted'], true)) {
                throw new HttpException(403, 'Tento e-mail nelze použít.');
            }
            if (($existing['oauth_provider'] ?? null) === $other) {
                throw new HttpException(409, $other === 'apple'
                    ? 'Tento e-mail už používá přihlášení přes Apple.'
                    : 'Tento e-mail už používá přihlášení přes Google.');
            }
            if (($existing['oauth_provider'] ?? null) === $provider && (string) $existing['oauth_uid'] !== $sub) {
                throw new HttpException(409, 'Tento e-mail je propojený s jiným účtem.');
            }
            $now = Clock::utc();
            $this->db->update('users', [
                'oauth_provider' => $provider,
                'oauth_uid' => $sub,
                'email_verified_at' => $existing['email_verified_at'] ?: $now,
                'status' => $existing['status'] === 'pending' ? 'active' : $existing['status'],
                'updated_at' => $now,
            ], 'id = :id', ['id' => (int) $existing['id']]);
            return $this->findById((int) $existing['id']);
        }

        [$first, $last] = $this->googleNames($profile);
        $now = Clock::utc();
        $userId = (int) $this->db->insert('users', [
            'public_id' => Crypto::uuid(),
            'email' => $email,
            'username' => $this->uniqueUsername(strstr($email, '@', true) ?: 'clen'),
            'first_name' => $first,
            'last_name' => $last,
            'oauth_provider' => $provider,
            'oauth_uid' => $sub,
            'role' => 'user',
            'plan' => 'free',
            'status' => 'active',
            'locale' => 'cs-CZ',
            'timezone' => 'Europe/Prague',
            'default_currency' => 'CZK',
            'email_verified_at' => $now,
            'terms_accepted_at' => $now,
            'privacy_accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $role = $this->db->fetch('SELECT id FROM roles WHERE slug = :s', ['s' => 'user']);
        if ($role) {
            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => (int) $role['id'],
                'assigned_at' => $now,
            ]);
        }
        $this->db->insert('notification_preferences', [
            'user_id' => $userId,
            'email_marketing' => 0,
        ]);
        $user = $this->findById($userId);
        try {
            $this->mail->queue('welcome', $email, [
                'subject' => 'Vítejte v PRIVOFIT',
                'first_name' => $user['first_name'],
            ], $userId);
        } catch (\Throwable) {
        }
        $this->notifyAdminsRegistered($user);
        return $user;
    }

    /** @param array<string, mixed> $user */
    private function notifyAdminsRegistered(array $user): void
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $publicId = trim((string) ($user['public_id'] ?? ''));
        $path = $publicId !== ''
            ? '/user/sprava/zakaznici/' . rawurlencode($publicId)
            : '/user/sprava/zakaznici';
        try {
            NotificationDispatcher::make($this->db)->notifyNow(
                'admin:user.registered:' . $id,
                'admin',
                'user.registered',
                null,
                [
                    'template' => 'admin-registration',
                    'push_type' => 'admin.sync',
                    'subject' => '✨ Nový účet',
                    'body' => CronText::person($user),
                    'action_url' => CronText::link($path),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Zpráva administrátorům o registraci se neodeslala', [
                'user' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array{given_name:string,family_name:string,name:string} $profile
     *  @return array{0:string,1:string}
     */
    private function googleNames(array $profile): array
    {
        $first = $profile['given_name'];
        $last = $profile['family_name'];
        if ($first === '' && $profile['name'] !== '') {
            $parts = preg_split('/\s+/u', $profile['name']) ?: [];
            $first = (string) array_shift($parts);
            $last = trim(implode(' ', $parts));
        }
        if ($first === '') {
            $first = 'Člen';
        }
        if ($last === '') {
            $last = '–';
        }
        return [mb_substr($first, 0, 100), mb_substr($last, 0, 100)];
    }

    private function uniqueUsername(string $seed): string
    {
        $base = preg_replace('/[^a-z0-9._]/', '', $this->normalizeUsername($seed)) ?? '';
        $base = trim($base, '._');
        if (strlen($base) < 3) {
            $base = 'clen';
        }
        $base = substr($base, 0, 24);
        $reserved = (array) config('app.reserved_usernames', []);
        for ($i = 0; $i < 40; $i++) {
            $suffix = $i === 0 ? '' : (string) $i;
            $candidate = substr($base, 0, 30 - strlen($suffix)) . $suffix;
            if (in_array($candidate, $reserved, true)) {
                continue;
            }
            if (!$this->db->fetch('SELECT id FROM users WHERE username = :u AND deleted_at IS NULL', ['u' => $candidate])) {
                return $candidate;
            }
        }
        return substr($base, 0, 22) . bin2hex(random_bytes(4));
    }

    public function loginFromApp(string $identifier, string $password, Request $request, ?string $totp = null): array
    {
        $this->issueMfaTrust = false;
        $user = $this->verifyCredentials($identifier, $password, $request, $totp);
        $this->db->update('users', [
            'last_login_at' => Clock::utc(),
            'last_login_ip' => $request->ip(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        return $this->findById((int) $user['id']);
    }

    public function assertOAuthAttempt(Request $request): void
    {
        $ip = $request->ip();
        if ($this->limiter->tooMany('oauth-app', $ip, 30, 15)) {
            throw new HttpException(429, 'Příliš mnoho pokusů o přihlášení. Zkuste to později.');
        }
        $this->limiter->hit('oauth-app', $ip);
    }

    /** @param array{sub:string,email:string,given_name:string,family_name:string,name:string} $profile */
    public function loginFromAppWithOAuth(string $provider, array $profile, Request $request, ?string $totp = null): array
    {
        $user = $this->findOrCreateOAuthUser($provider, $profile);
        if (in_array($user['status'], ['blocked', 'deleted'], true) || !empty($user['deleted_at'])) {
            throw new HttpException(403, 'Tento účet je zablokovaný.');
        }
        if ((int) $user['mfa_enabled'] === 1) {
            if ($totp === null || $totp === '') {
                throw new MfaRequiredException($user);
            }
            if (!$this->verifyTotp($user, $totp) && !$this->consumeRecoveryCode($user, $totp)) {
                throw new HttpException(422, 'Neplatný ověřovací kód.');
            }
        }
        $this->db->update('users', [
            'last_login_at' => Clock::utc(),
            'last_login_ip' => $request->ip(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        return $this->findById((int) $user['id']);
    }

    public function issueMobileLoginTicket(array $user): string
    {
        $raw = Crypto::token(32);
        $this->db->insert('oauth_mobile_tickets', [
            'token_hash' => Crypto::hash($raw),
            'user_id' => (int) $user['id'],
            'expires_at' => Clock::nowUtc()->modify('+2 minutes')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        return $raw;
    }

    public function consumeMobileLoginTicket(string $ticket): array
    {
        $ticket = trim($ticket);
        if ($ticket === '' || strlen($ticket) > 128) {
            throw new HttpException(422, 'Přihlášení přes Google vypršelo. Zkus to znovu.');
        }
        $row = $this->db->fetch(
            'SELECT * FROM oauth_mobile_tickets WHERE token_hash = :h',
            ['h' => Crypto::hash($ticket)]
        );
        if (!$row || $row['used_at'] !== null || $row['expires_at'] < Clock::utc()) {
            throw new HttpException(422, 'Přihlášení přes Google vypršelo. Zkus to znovu.');
        }
        $used = $this->db->update('oauth_mobile_tickets', [
            'used_at' => Clock::utc(),
        ], 'id = :id AND used_at IS NULL', ['id' => (int) $row['id']]);
        if ($used !== 1) {
            throw new HttpException(422, 'Přihlášení přes Google vypršelo. Zkus to znovu.');
        }
        $user = $this->findById((int) $row['user_id']);
        if (in_array($user['status'], ['blocked', 'deleted'], true) || !empty($user['deleted_at'])) {
            throw new HttpException(403, 'Tento účet je zablokovaný.');
        }
        return $user;
    }

    public function verifyCredentials(string $identifier, string $password, Request $request, ?string $totp = null): array
    {
        $identifier = trim($identifier);
        $lookupKey = $identifier;
        if (str_contains($identifier, '@')) {
            $lookupKey = $this->normalizeEmail($identifier);
            $user = $this->db->fetch('SELECT * FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => $lookupKey]);
        } else {
            $lookupKey = $this->normalizeUsername($identifier);
            $user = $this->db->fetch('SELECT * FROM users WHERE username = :u AND deleted_at IS NULL', ['u' => $lookupKey]);
        }
        $ip = $request->ip();
        $generic = 'E-mail, uživatelské jméno nebo heslo není správné.';

        if ($this->limiter->tooMany('login-ip', $ip, (int) config('security.login.max_attempts_ip', 20), 15)
            || $this->limiter->tooMany('login-id', $lookupKey, (int) config('security.login.max_attempts_account', 5), 15)
        ) {
            $this->db->insert('login_attempts', [
                'identifier' => $lookupKey,
                'ip_address' => $ip,
                'successful' => 0,
                'created_at' => Clock::utc(),
            ]);
            throw new HttpException(429, 'Příliš mnoho pokusů o přihlášení. Zkuste to později.');
        }

        $valid = $user && is_string($user['password_hash'] ?? null) && Crypto::verifyPassword($password, $user['password_hash']);
        $this->limiter->hit('login-ip', $ip);
        $this->limiter->hit('login-id', $lookupKey);

        if (!$valid || in_array($user['status'], ['blocked', 'deleted'], true)) {
            $this->db->insert('login_attempts', [
                'identifier' => $lookupKey,
                'ip_address' => $ip,
                'successful' => 0,
                'created_at' => Clock::utc(),
            ]);
            throw new HttpException(422, $generic);
        }

        if ((int) $user['mfa_enabled'] === 1 && !$this->browserIsTrusted((int) $user['id'])) {
            if ($totp === null || $totp === '') {
                throw new MfaRequiredException($user);
            }
            if (!$this->verifyTotp($user, $totp) && !$this->consumeRecoveryCode($user, $totp)) {
                throw new HttpException(422, 'Neplatný ověřovací kód.');
            }
            $this->issueMfaTrust = true;
        }

        $this->db->insert('login_attempts', [
            'identifier' => $lookupKey,
            'ip_address' => $ip,
            'successful' => 1,
            'created_at' => Clock::utc(),
        ]);

        return $user;
    }

    public function completeMfaLogin(int $userId, string $totp, Request $request, bool $remember = false): array
    {
        $user = $this->findById($userId);
        if (!$this->verifyTotp($user, $totp) && !$this->consumeRecoveryCode($user, $totp)) {
            throw new HttpException(401, 'Neplatný ověřovací kód.');
        }
        $sessionUser = $this->establishWebSession($user, $request, $remember);
        $this->rememberBrowser((int) $user['id'], $request);
        return $sessionUser;
    }

    public function establishWebSession(array $user, Request $request, bool $remember = false): array
    {
        Session::regenerate();
        $lifetimeMinutes = $remember
            ? (int) config('security.session.remember_days', 30) * 1440
            : (int) config('security.session.lifetime', 120);
        $expires = Clock::nowUtc()->modify('+' . $lifetimeMinutes . ' minutes')->format('Y-m-d H:i:s');
        $raw = Crypto::token(48);
        $sessionId = (int) $this->db->insert('user_sessions', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash($raw),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_remembered' => $remember ? 1 : 0,
            'last_activity_at' => Clock::utc(),
            'expires_at' => $expires,
            'created_at' => Clock::utc(),
        ]);
        Session::set('user_id', (int) $user['id']);
        Session::set('auth_session_id', $sessionId);
        $this->db->update('users', [
            'last_login_at' => Clock::utc(),
            'last_login_ip' => $request->ip(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        return $this->findById((int) $user['id']);
    }

    public function logout(Request $request): void
    {
        $sessionId = Session::get('auth_session_id');
        if (is_numeric($sessionId)) {
            $this->db->update('user_sessions', ['revoked_at' => Clock::utc()], 'id = :id', ['id' => (int) $sessionId]);
        }
        Session::destroy();
    }

    public function logoutAll(int $userId, ?int $exceptSessionId = null): void
    {
        $sql = 'UPDATE user_sessions SET revoked_at = :now WHERE user_id = :uid AND revoked_at IS NULL';
        $params = ['now' => Clock::utc(), 'uid' => $userId];
        if ($exceptSessionId) {
            $sql .= ' AND id != :sid';
            $params['sid'] = $exceptSessionId;
        }
        $this->db->query($sql, $params);
        $this->db->query(
            'UPDATE api_refresh_tokens SET revoked_at = :now WHERE user_id = :uid AND revoked_at IS NULL',
            ['now' => Clock::utc(), 'uid' => $userId]
        );
        $this->revokeTrustedBrowsers($userId);
    }

    public function sendVerification(array $user): void
    {
        $raw = Crypto::token(32);
        $this->db->insert('email_verifications', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash($raw),
            'expires_at' => Clock::nowUtc()->modify('+' . (int) config('app.email_verify_ttl_minutes', 60) . ' minutes')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        $this->mail->queue('verify-email', $user['email'], [
            'subject' => 'Ověření e-mailové adresy',
            'first_name' => $user['first_name'],
            'action_url' => absolute_url('/overeni-emailu?token=' . rawurlencode($raw)),
        ], (int) $user['id']);
    }

    public function verifyEmail(string $token): void
    {
        $row = $this->db->fetch(
            'SELECT * FROM email_verifications WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
            ['h' => Crypto::hash($token), 'now' => Clock::utc()]
        );
        if (!$row) {
            throw new HttpException(400, 'Odkaz pro ověření je neplatný nebo vypršel.');
        }
        $this->db->transaction(function (Database $db) use ($row): void {
            $db->update('email_verifications', ['used_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
            $db->update('users', [
                'email_verified_at' => Clock::utc(),
                'status' => 'active',
            ], 'id = :id AND status != :blocked', ['id' => (int) $row['user_id'], 'blocked' => 'blocked']);
        });
    }

    public function forgotPassword(string $email, Request $request): void
    {
        if (!$this->limiter->attempt('forgot', $request->ip(), 3, 60)) {
            throw new HttpException(429, 'Příliš mnoho požadavků. Zkuste to později.');
        }
        $email = $this->normalizeEmail($email);
        $user = $this->db->fetch('SELECT * FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => $email]);
        if (!$user || !is_string($user['password_hash'] ?? null)) {
            return;
        }
        $raw = Crypto::token(32);
        $this->db->insert('password_resets', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash($raw),
            'ip_address' => $request->ip(),
            'expires_at' => Clock::nowUtc()->modify('+' . (int) config('app.password_reset_ttl_minutes', 30) . ' minutes')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        $this->mail->queue('reset-password', $user['email'], [
            'subject' => 'Obnovení hesla',
            'first_name' => $user['first_name'],
            'action_url' => absolute_url('/obnoveni-hesla?token=' . rawurlencode($raw)),
        ], (int) $user['id']);
    }

    public function resetPassword(string $token, string $password, string $confirmation): void
    {
        $validator = new Validator();
        $validator->minLength('password', $password, (int) config('app.password_min_length', 12));
        $validator->confirmed('password', $password, $confirmation);
        if ($this->breaches->isCompromised($password)) {
            $validator->add('password', 'Toto heslo je v seznamech uniklých hesel. Zvolte jiné.');
        }
        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $validator->first() ?? 'Zkontrolujte heslo.');
        }
        $row = $this->db->fetch(
            'SELECT * FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
            ['h' => Crypto::hash($token), 'now' => Clock::utc()]
        );
        if (!$row) {
            throw new HttpException(400, 'Odkaz pro obnovení hesla je neplatný nebo vypršel.');
        }
        $user = $this->findById((int) $row['user_id']);
        $this->db->transaction(function (Database $db) use ($row, $password): void {
            $db->update('password_resets', ['used_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
            $db->update('users', ['password_hash' => Crypto::hashPassword($password)], 'id = :id', ['id' => (int) $row['user_id']]);
        });
        $this->logoutAll((int) $row['user_id']);
        $this->mail->queue('security-alert', $user['email'], [
            'subject' => 'Vaše heslo bylo změněno',
            'first_name' => $user['first_name'],
            'body' => 'Heslo k vašemu účtu PRIVOFIT bylo právě změněno. Pokud jste to nebyli vy, okamžitě nás kontaktujte.',
        ], (int) $user['id']);
    }

    public function changePassword(array $user, string $current, string $new, string $confirmation, ?int $sessionId = null): void
    {
        if (!Crypto::verifyPassword($current, (string) $user['password_hash'])) {
            throw new ValidationException(['current_password' => ['Současné heslo není správné.']], 'Současné heslo není správné.');
        }
        $validator = new Validator();
        $validator->minLength('password', $new, (int) config('app.password_min_length', 12));
        $validator->confirmed('password', $new, $confirmation);
        if ($this->breaches->isCompromised($new)) {
            $validator->add('password', 'Toto heslo je v seznamech uniklých hesel. Zvolte jiné.');
        }
        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $validator->first() ?? 'Zkontrolujte heslo.');
        }
        $this->db->update('users', ['password_hash' => Crypto::hashPassword($new)], 'id = :id', ['id' => (int) $user['id']]);
        $this->logoutAll((int) $user['id'], $sessionId);
        $this->mail->queue('security-alert', $user['email'], [
            'subject' => 'Vaše heslo bylo změněno',
            'first_name' => $user['first_name'],
            'body' => 'Heslo k vašemu účtu PRIVOFIT bylo změněno.',
        ], (int) $user['id']);
    }

    public function requestEmailChange(array $user, string $newEmail): void
    {
        $newEmail = $this->normalizeEmail($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['Zadejte platnou e-mailovou adresu.']], 'Zadejte platnou e-mailovou adresu.');
        }
        if ($this->db->fetch('SELECT id FROM users WHERE email = :e AND id != :id AND deleted_at IS NULL', ['e' => $newEmail, 'id' => (int) $user['id']])) {
            throw new ValidationException(['email' => ['Tento e-mail nelze použít.']], 'Tento e-mail nelze použít.');
        }
        $raw = Crypto::token(32);
        $this->db->insert('email_change_requests', [
            'user_id' => (int) $user['id'],
            'new_email' => $newEmail,
            'token_hash' => Crypto::hash($raw),
            'expires_at' => Clock::nowUtc()->modify('+60 minutes')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        $this->mail->queue('verify-email', $newEmail, [
            'subject' => 'Potvrzení nové e-mailové adresy',
            'first_name' => $user['first_name'],
            'body' => 'Potvrď novou e-mailovou adresu tlačítkem níže. Odkaz platí 60 minut.',
            'action_url' => absolute_url('/potvrzeni-emailu?token=' . rawurlencode($raw)),
        ], (int) $user['id']);
    }

    public function confirmEmailChange(string $token): void
    {
        $row = $this->db->fetch(
            'SELECT * FROM email_change_requests WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
            ['h' => Crypto::hash($token), 'now' => Clock::utc()]
        );
        if (!$row) {
            throw new HttpException(400, 'Odkaz je neplatný nebo vypršel.');
        }
        $this->db->transaction(function (Database $db) use ($row): void {
            $db->update('email_change_requests', ['used_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
            $db->update('users', [
                'email' => $row['new_email'],
                'email_verified_at' => Clock::utc(),
            ], 'id = :id', ['id' => (int) $row['user_id']]);
        });
    }

    public function updateProfile(array $user, array $input): array
    {
        $data = [
            'first_name' => trim((string) ($input['first_name'] ?? $user['first_name'])),
            'last_name' => trim((string) ($input['last_name'] ?? $user['last_name'])),
        ];
        if (array_key_exists('phone', $input)) {
            $phone = $this->phone->normalize((string) $input['phone']);
            if ((string) $input['phone'] !== '' && $phone === null) {
                throw new ValidationException(['phone' => ['Zadejte platné telefonní číslo.']], 'Zadejte platné telefonní číslo.');
            }
            $data['phone'] = $phone;
        }
        if (!empty($input['username']) && $this->normalizeUsername((string) $input['username']) !== $user['username']) {
            $this->changeUsername($user, (string) $input['username']);
        }
        $this->db->update('users', $data, 'id = :id', ['id' => (int) $user['id']]);
        return $this->findById((int) $user['id']);
    }

    public function changeUsername(array $user, string $username): void
    {
        $username = $this->normalizeUsername($username);
        $validator = new Validator();
        $validator->username('username', $username);
        if (in_array($username, (array) config('app.reserved_usernames', []), true)) {
            $validator->add('username', 'Toto uživatelské jméno není k dispozici.');
        }
        if ($this->db->fetch('SELECT id FROM users WHERE username = :u AND id != :id', ['u' => $username, 'id' => (int) $user['id']])) {
            $validator->add('username', 'Toto uživatelské jméno je již obsazené.');
        }
        $days = (int) config('app.username_change_days', 14);
        if ($user['username_changed_at']) {
            $last = new \DateTimeImmutable($user['username_changed_at'], new \DateTimeZone('UTC'));
            if ($last->modify('+' . $days . ' days') > Clock::nowUtc()) {
                $validator->add('username', 'Uživatelské jméno lze změnit jednou za ' . $days . ' dní.');
            }
        }
        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $validator->first() ?? 'Uživatelské jméno nelze změnit.');
        }
        $this->db->insert('username_changes', [
            'user_id' => (int) $user['id'],
            'old_username' => $user['username'],
            'new_username' => $username,
            'created_at' => Clock::utc(),
        ]);
        $this->db->update('users', [
            'username' => $username,
            'username_changed_at' => Clock::utc(),
        ], 'id = :id', ['id' => (int) $user['id']]);
    }

    public function publicUser(array $user): array
    {
        return [
            'public_id' => $user['public_id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'avatar_url' => !empty($user['avatar_path']) ? avatar_url($user) : null,
            'role' => $user['role'],
            'plan' => $user['plan'],
            'status' => $user['status'],
            'email_verified' => !empty($user['email_verified_at']),
            'mfa_enabled' => (int) $user['mfa_enabled'] === 1,
            'created_at' => $user['created_at'],
        ];
    }

    public function issueApiTokens(array $user, Request $request, string $deviceName = 'Mobilní aplikace', string $platform = 'other'): array
    {
        $deviceId = (int) $this->db->insert('api_devices', [
            'public_id' => Crypto::uuid(),
            'user_id' => (int) $user['id'],
            'name' => $deviceName,
            'platform' => in_array($platform, ['ios', 'android', 'web', 'other'], true) ? $platform : 'other',
            'last_seen_at' => Clock::utc(),
            'created_at' => Clock::utc(),
        ]);
        $family = Crypto::uuid();
        $refreshRaw = Crypto::token(48);
        $this->db->insert('api_refresh_tokens', [
            'user_id' => (int) $user['id'],
            'device_id' => $deviceId,
            'token_hash' => Crypto::hash($refreshRaw),
            'family_id' => $family,
            'expires_at' => Clock::nowUtc()->modify('+' . (int) config('app.refresh_token_ttl_days', 30) . ' days')->format('Y-m-d H:i:s'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => Clock::utc(),
        ]);
        $access = Crypto::signPayload([
            'sub' => $user['public_id'],
            'did' => $deviceId,
            'iat' => time(),
            'exp' => time() + ((int) config('app.access_token_ttl_minutes', 15) * 60),
            'typ' => 'access',
        ]);
        return [
            'access_token' => $access,
            'refresh_token' => $refreshRaw,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('app.access_token_ttl_minutes', 15) * 60,
        ];
    }

    public function refreshApiToken(string $refreshToken, Request $request): array
    {
        $row = $this->db->fetch(
            'SELECT * FROM api_refresh_tokens WHERE token_hash = :h',
            ['h' => Crypto::hash($refreshToken)]
        );
        if (!$row) {
            throw new HttpException(401, 'Neplatný obnovovací token.');
        }
        if ($row['revoked_at'] !== null || $row['reused_at'] !== null) {
            $this->db->query(
                'UPDATE api_refresh_tokens SET revoked_at = :now, reused_at = :now WHERE family_id = :f',
                ['now' => Clock::utc(), 'f' => $row['family_id']]
            );
            throw new HttpException(401, 'Obnovovací token byl zneplatněn.');
        }
        if ($row['expires_at'] < Clock::utc()) {
            throw new HttpException(401, 'Obnovovací token vypršel.');
        }
        $user = $this->findById((int) $row['user_id']);
        $newRaw = Crypto::token(48);
        $newId = (int) $this->db->insert('api_refresh_tokens', [
            'user_id' => (int) $user['id'],
            'device_id' => $row['device_id'],
            'token_hash' => Crypto::hash($newRaw),
            'family_id' => $row['family_id'],
            'expires_at' => Clock::nowUtc()->modify('+' . (int) config('app.refresh_token_ttl_days', 30) . ' days')->format('Y-m-d H:i:s'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => Clock::utc(),
        ]);
        $this->db->update('api_refresh_tokens', [
            'revoked_at' => Clock::utc(),
            'replaced_by' => $newId,
        ], 'id = :id', ['id' => (int) $row['id']]);

        $access = Crypto::signPayload([
            'sub' => $user['public_id'],
            'did' => $row['device_id'],
            'iat' => time(),
            'exp' => time() + ((int) config('app.access_token_ttl_minutes', 15) * 60),
            'typ' => 'access',
        ]);
        return [
            'access_token' => $access,
            'refresh_token' => $newRaw,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('app.access_token_ttl_minutes', 15) * 60,
        ];
    }

    public function beginTotpSetup(array $user): array
    {
        if ((int) $user['mfa_enabled'] === 1) {
            throw new HttpException(400, 'Dvoufaktorové ověření už je aktivní.');
        }

        $label = self::totpLabel($user);
        $row = $this->db->fetch(
            'SELECT * FROM totp_secrets WHERE user_id = :id AND confirmed_at IS NULL ORDER BY id DESC LIMIT 1',
            ['id' => (int) $user['id']]
        );

        $totp = null;
        if ($row) {
            try {
                $totp = TOTP::createFromSecret(Crypto::decrypt($row['secret_encrypted']));
            } catch (\Throwable) {
                $this->db->query('DELETE FROM totp_secrets WHERE user_id = :id AND confirmed_at IS NULL', ['id' => (int) $user['id']]);
            }
        }
        if ($totp === null) {
            $totp = TOTP::generate(null, 20);
            $this->db->query('DELETE FROM totp_secrets WHERE user_id = :id', ['id' => (int) $user['id']]);
            $this->db->insert('totp_secrets', [
                'user_id' => (int) $user['id'],
                'secret_encrypted' => Crypto::encrypt($totp->getSecret()),
                'created_at' => Clock::utc(),
            ]);
        }

        $totp->setLabel($label);
        $totp->setIssuer('PRIVOFIT');
        $otpauth = $totp->getProvisioningUri();
        $secret = $totp->getSecret();
        Session::set('mfa_setup_otpauth', $otpauth);

        return [
            'secret' => $secret,
            'secret_grouped' => strtoupper(trim(chunk_split($secret, 4, ' '))),
            'otpauth' => $otpauth,
        ];
    }

    private static function totpLabel(array $user): string
    {
        $label = trim(str_replace([':', '%3A', '%3a'], '', (string) ($user['email'] ?? '')));
        if ($label === '') {
            $label = trim(str_replace([':', '%3A', '%3a'], '', (string) ($user['username'] ?? 'user')));
        }
        return $label !== '' ? $label : 'user';
    }

    public function totpProvisioningUri(array $user): string
    {
        $row = $this->db->fetch(
            'SELECT * FROM totp_secrets WHERE user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => (int) $user['id']]
        );
        if (!$row) {
            throw new HttpException(404, 'Nejprve zahajte nastavení MFA.');
        }
        $totp = TOTP::createFromSecret(Crypto::decrypt($row['secret_encrypted']));
        $totp->setLabel(self::totpLabel($user));
        $totp->setIssuer('PRIVOFIT');
        return $totp->getProvisioningUri();
    }

    public function confirmTotp(array $user, string $code): array
    {
        if ((int) $user['mfa_enabled'] === 1) {
            throw new HttpException(400, 'Dvoufaktorové ověření už je aktivní.');
        }
        $row = $this->db->fetch('SELECT * FROM totp_secrets WHERE user_id = :id ORDER BY id DESC LIMIT 1', ['id' => (int) $user['id']]);
        if (!$row) {
            throw new HttpException(400, 'Nejprve zahajte nastavení MFA.');
        }
        $secret = Crypto::decrypt($row['secret_encrypted']);
        $totp = TOTP::createFromSecret($secret);
        if (!$this->totpCodeMatches($totp, $code)) {
            throw new HttpException(400, 'Neplatný ověřovací kód.');
        }
        $this->db->update('totp_secrets', ['confirmed_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
        $this->db->update('users', ['mfa_enabled' => 1], 'id = :id', ['id' => (int) $user['id']]);
        $codes = [];
        $this->db->query('DELETE FROM recovery_codes WHERE user_id = :id', ['id' => (int) $user['id']]);
        for ($i = 0; $i < 8; $i++) {
            $codePlain = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = $codePlain;
            $this->db->insert('recovery_codes', [
                'user_id' => (int) $user['id'],
                'code_hash' => Crypto::hash($codePlain),
                'created_at' => Clock::utc(),
            ]);
        }
        $this->audit->log((int) $user['id'], 'mfa.enable', 'user', $user['id'], null, ['mfa' => true]);
        return $codes;
    }

    public function disableTotp(array $user, string $password): void
    {
        if (self::mfaRequiredFor($user)) {
            throw new HttpException(403, 'Pro tento účet nelze dvoufaktorové ověření vypnout.');
        }
        if ((int) $user['mfa_enabled'] !== 1) {
            throw new HttpException(400, 'Dvoufaktorové ověření není zapnuté.');
        }
        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            throw new ValidationException(['current_password' => ['Současné heslo není správné.']], 'Současné heslo není správné.');
        }
        $this->db->update('users', ['mfa_enabled' => 0], 'id = :id', ['id' => (int) $user['id']]);
        $this->db->query('DELETE FROM totp_secrets WHERE user_id = :id', ['id' => (int) $user['id']]);
        $this->db->query('DELETE FROM recovery_codes WHERE user_id = :id', ['id' => (int) $user['id']]);
        $this->revokeTrustedBrowsers((int) $user['id']);
        $this->audit->log((int) $user['id'], 'mfa.disable', 'user', $user['id'], ['mfa' => true], ['mfa' => false]);
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c FROM recovery_codes WHERE user_id = :id AND used_at IS NULL',
            ['id' => $userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public static function mfaRequiredFor(array $user): bool
    {
        return in_array($user['role'] ?? '', (array) config('security.mfa_required_roles', []), true);
    }

    public function sessions(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id, ip_address, user_agent, is_remembered, last_activity_at, expires_at, created_at
             FROM user_sessions
             WHERE user_id = :id AND revoked_at IS NULL AND expires_at > :now
             ORDER BY last_activity_at DESC',
            ['id' => $userId, 'now' => Clock::utc()]
        );
    }

    public function revokeSession(int $userId, int $sessionId): void
    {
        $this->db->update(
            'user_sessions',
            ['revoked_at' => Clock::utc()],
            'id = :id AND user_id = :uid',
            ['id' => $sessionId, 'uid' => $userId]
        );
    }

    public function findById(int $id): array
    {
        $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw new HttpException(404, 'Uživatel nebyl nalezen.');
        }
        return $user;
    }

    public function registerFromApp(array $input, Request $request): array
    {
        $first = trim((string) ($input['firstName'] ?? $input['first_name'] ?? ''));
        $last = trim((string) ($input['lastName'] ?? $input['last_name'] ?? ''));
        $username = $this->normalizeUsername((string) ($input['username'] ?? ''));
        $username = strtr($username, [' ' => '_', '-' => '_']);
        $username = preg_replace('/[^a-z0-9._]/', '', $username) ?? $username;
        $prepared = $input;
        $prepared['first_name'] = $first;
        $prepared['last_name'] = $last;
        $prepared['username'] = $username;
        $prepared['email'] = (string) ($input['email'] ?? '');
        $prepared['password'] = (string) ($input['password'] ?? '');
        $prepared['password_confirmation'] = (string) ($input['password_confirmation'] ?? $input['passwordConfirmation'] ?? '');
        $prepared['terms'] = $input['terms'] ?? null;
        $prepared['privacy'] = $input['privacy'] ?? null;
        $user = $this->register($prepared, $request);
        $this->db->update('users', [
            'email_verified_at' => Clock::utc(),
            'status' => 'active',
        ], 'id = :id AND status != :blocked', [
            'id' => (int) $user['id'],
            'blocked' => 'blocked',
        ]);
        return $this->findById((int) $user['id']);
    }

    public function forgotPasswordFromApp(string $identifier, Request $request): void
    {
        $identifier = trim($identifier);
        if ($identifier !== '' && !str_contains($identifier, '@')) {
            $user = $this->db->fetch(
                'SELECT email FROM users WHERE username = :u AND deleted_at IS NULL',
                ['u' => $this->normalizeUsername($identifier)]
            );
            $identifier = (string) ($user['email'] ?? 'nobody@invalid.example');
        }
        $this->forgotPassword($identifier, $request);
    }

    public function deleteAccount(array $user, ?string $reason = null): void
    {
        $id = (int) $user['id'];
        $this->logoutAll($id);
        $this->avatars->delete($user);

        $this->db->transaction(function () use ($id): void {
            $now = Clock::utc();
            $this->db->query(
                "UPDATE reservations
                 SET status = 'cancelled',
                     cancelled_at = :cancelled_at,
                     cancellation_reason = :reason,
                     updated_at = :updated_at
                 WHERE user_id = :uid
                   AND status IN ('pending_payment', 'confirmed')
                   AND starts_at > :starts_after",
                [
                    'uid' => $id,
                    'cancelled_at' => $now,
                    'updated_at' => $now,
                    'starts_after' => $now,
                    'reason' => 'Účet byl smazán.',
                ]
            );
            $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $id]);
        });
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    private function verifyTotp(array $user, string $code): bool
    {
        $row = $this->db->fetch(
            'SELECT * FROM totp_secrets WHERE user_id = :id AND confirmed_at IS NOT NULL ORDER BY id DESC LIMIT 1',
            ['id' => (int) $user['id']]
        );
        if (!$row) {
            return false;
        }
        try {
            $totp = TOTP::createFromSecret(Crypto::decrypt($row['secret_encrypted']));
        } catch (\Throwable) {
            return false;
        }
        return $this->totpCodeMatches($totp, $code);
    }

    private function totpCodeMatches(TOTP $totp, string $code): bool
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($digits) !== $totp->getDigits()) {
            return false;
        }
        $period = $totp->getPeriod();
        $now = time();
        for ($step = -4; $step <= 4; $step++) {
            $at = $now + ($step * $period);
            if ($at < 0) {
                continue;
            }
            if ($totp->verify($digits, $at)) {
                return true;
            }
        }
        return false;
    }

    private function mfaTrustCookie(): string
    {
        $name = (string) config('security.session.mfa_trust_cookie', 'privofit_mfa');
        return $name !== '' ? $name : 'privofit_mfa';
    }

    private function mfaTrustDays(): int
    {
        return max(1, (int) config('security.session.mfa_trust_days', 30));
    }

    private function browserIsTrusted(int $userId): bool
    {
        $raw = $_COOKIE[$this->mfaTrustCookie()] ?? '';
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $row = $this->db->fetch(
            'SELECT id FROM mfa_trusted_devices WHERE user_id = :uid AND token_hash = :h AND expires_at > :now LIMIT 1',
            ['uid' => $userId, 'h' => Crypto::hash($raw), 'now' => Clock::utc()]
        );
        if (!$row) {
            return false;
        }
        $this->db->update('mfa_trusted_devices', ['last_used_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
        return true;
    }

    private function rememberBrowser(int $userId, Request $request): void
    {
        $raw = Crypto::token(32);
        $days = $this->mfaTrustDays();
        $this->db->insert('mfa_trusted_devices', [
            'user_id' => $userId,
            'token_hash' => Crypto::hash($raw),
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent(), 0, 500),
            'expires_at' => Clock::nowUtc()->modify('+' . $days . ' days')->format('Y-m-d H:i:s'),
            'last_used_at' => Clock::utc(),
            'created_at' => Clock::utc(),
        ]);
        $this->setTrustCookie($raw, time() + ($days * 86400));
    }

    private function revokeTrustedBrowsers(int $userId): void
    {
        $name = $this->mfaTrustCookie();
        $raw = $_COOKIE[$name] ?? '';
        if (is_string($raw) && $raw !== '') {
            $row = $this->db->fetch(
                'SELECT id FROM mfa_trusted_devices WHERE user_id = :uid AND token_hash = :h LIMIT 1',
                ['uid' => $userId, 'h' => Crypto::hash($raw)]
            );
            if ($row) {
                $this->setTrustCookie('', time() - 42000);
            }
        }
        $this->db->query('DELETE FROM mfa_trusted_devices WHERE user_id = :uid', ['uid' => $userId]);
    }

    private function setTrustCookie(string $value, int $expires): void
    {
        $name = $this->mfaTrustCookie();
        if ($value === '') {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
        if (headers_sent()) {
            return;
        }
        $secure = (bool) config('security.session.secure', false);
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $secure = true;
        }
        $sameSite = (string) config('security.session.same_site', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    private function consumeRecoveryCode(array $user, string $code): bool
    {
        $row = $this->db->fetch(
            'SELECT * FROM recovery_codes WHERE user_id = :id AND used_at IS NULL AND code_hash = :h',
            ['id' => (int) $user['id'], 'h' => Crypto::hash(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''))]
        );
        if (!$row) {
            return false;
        }
        $this->db->update('recovery_codes', ['used_at' => Clock::utc()], 'id = :id', ['id' => (int) $row['id']]);
        return true;
    }
}
