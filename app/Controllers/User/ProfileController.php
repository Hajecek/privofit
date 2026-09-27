<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\QrSvg;
use App\Services\Auth\AuthService;
use App\Services\Auth\ValidationException;
use App\Services\AvatarService;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentService;
use App\Services\MembershipService;
use App\Services\ReservationService;

final class ProfileController extends Controller
{
    public function index(): never
    {
        $user = $this->requireUser();
        $auth = AuthService::make($this->app->db());
        $this->view('user/profile', [
            'title' => 'Profil',
            'sessions' => $auth->sessions((int) $user['id']),
            'currentSession' => $this->app->auth()->sessionRowId(),
            'membership' => (new MembershipService($this->app->db()))->activeForUser((int) $user['id']),
            'reservations' => ReservationService::make($this->app->db())->forUser((int) $user['id']),
            'payments' => (new PaymentService($this->app->db()))->forUser((int) $user['id']),
            'mfaEnabled' => (int) $user['mfa_enabled'] === 1,
            'mfaRequired' => AuthService::mfaRequiredFor($user),
            'errors' => Session::pull('errors', []),
            'old' => Session::pull('_old', []),
            'pageScripts' => ['js/profile.js'],
        ]);
    }

    public function membership(): never
    {
        $user = $this->requireUser();
        $service = new MembershipService($this->app->db());
        $this->view('user/membership', [
            'title' => 'Členství',
            'plans' => $service->plans(),
            'current' => $service->activeForUser((int) $user['id']),
            'history' => $service->history((int) $user['id']),
            'pageScripts' => ['js/membership.js'],
        ]);
    }

    public function buyMembership(Request $request): never
    {
        $user = $this->requireUser();
        $service = new MembershipService($this->app->db());
        try {
            $pending = $service->beginPurchase((int) $user['id'], (string) $request->input('plan', ''));
            $url = CheckoutService::make($this->app->db())->startMembership($user, $pending, $this->app);
        } catch (HttpException $e) {
            if ($request->wantsJson()) {
                $this->jsonError($e->getMessage(), $e->status);
            }
            $this->flashError($e->getMessage());
            $this->redirect('/user/clenstvi');
        }
        if ($request->wantsJson()) {
            $this->jsonOk(['checkout_url' => $url]);
        }
        Response::redirect($url);
    }

    public function membershipPaid(Request $request): never
    {
        $user = $this->requireUser();
        $sessionId = trim((string) $request->query('session_id', ''));
        try {
            $payment = CheckoutService::make($this->app->db())->fulfillSession($sessionId);
            if ((int) ($payment['user_id'] ?? 0) !== (int) $user['id']) {
                throw new HttpException(403, 'Tato platba nepatří k tvému účtu.');
            }
            $this->flashSuccess('Platba prošla. Členství je na účtu a vstupy můžeš čerpat rezervací dne.');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user');
    }

    public function membershipCheckoutCancel(Request $request): never
    {
        $user = $this->requireUser();
        $paymentId = trim((string) $request->query('platba', ''));
        if ($paymentId !== '') {
            CheckoutService::make($this->app->db())->cancelHold($paymentId, $user);
        }
        $this->flashError('Platba se nedokončila. Tarif se nezapsal.');
        $this->redirect('/user/clenstvi');
    }

    public function update(Request $request): never
    {
        $user = $this->requireUser();
        $auth = AuthService::make($this->app->db());
        $input = $request->all();
        $email = trim((string) ($input['email'] ?? ''));
        $emailChanged = $email !== '' && strcasecmp($email, (string) $user['email']) !== 0;
        $passwordTouched = trim((string) ($input['current_password'] ?? '')) !== ''
            || trim((string) ($input['password'] ?? '')) !== ''
            || trim((string) ($input['password_confirmation'] ?? '')) !== '';
        $notes = [];
        try {
            $this->app->db()->transaction(function () use ($auth, $user, $input, $email, $emailChanged, $passwordTouched, &$notes): void {
                $auth->updateProfile($user, $input);
                $fresh = $auth->findById((int) $user['id']);
                if ($emailChanged) {
                    $auth->requestEmailChange($fresh, $email);
                    $notes[] = 'Na novou adresu jsme poslali ověřovací odkaz.';
                }
                if ($passwordTouched) {
                    $auth->changePassword(
                        $fresh,
                        (string) ($input['current_password'] ?? ''),
                        (string) ($input['password'] ?? ''),
                        (string) ($input['password_confirmation'] ?? ''),
                        $this->app->auth()->sessionRowId()
                    );
                    $notes[] = 'Heslo je změněné a ostatní zařízení jsou odhlášená.';
                }
            });
            $this->flashSuccess($notes === [] ? 'Úpravy jsou uložené.' : 'Úpravy jsou uložené. ' . implode(' ', $notes));
        } catch (ValidationException $e) {
            $this->rememberOld($request);
            Session::set('errors', $e->errors);
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/profil');
    }

    public function password(Request $request): never
    {
        $user = $this->requireUser();
        try {
            AuthService::make($this->app->db())->changePassword(
                $user,
                (string) $request->input('current_password', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
                $this->app->auth()->sessionRowId()
            );
            $this->flashSuccess('Heslo bylo změněno. Ostatní relace byly odhlášeny.');
        } catch (ValidationException $e) {
            Session::set('errors', $e->errors);
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/profil');
    }

    public function email(Request $request): never
    {
        $user = $this->requireUser();
        try {
            AuthService::make($this->app->db())->requestEmailChange($user, (string) $request->input('email', ''));
            $this->flashSuccess('Na novou adresu jsme odeslali ověřovací odkaz.');
        } catch (ValidationException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/profil');
    }

    public function avatar(Request $request): never
    {
        $user = $this->requireUser();
        $file = $request->file('avatar');
        if (!$file) {
            $this->flashError('Vyberte obrázek.');
            $this->redirect('/user/profil');
        }
        try {
            (new AvatarService($this->app->db()))->storeFromUpload($user, $file, [
                'x' => $request->input('avatar_x'),
                'y' => $request->input('avatar_y'),
                'size' => $request->input('avatar_size'),
            ]);
            $this->flashSuccess('Profilový obrázek byl uložen.');
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/profil');
    }

    public function deleteAvatar(): never
    {
        $user = $this->requireUser();
        (new AvatarService($this->app->db()))->delete($user);
        $this->flashSuccess('Profilový obrázek byl odstraněn.');
        $this->redirect('/user/profil');
    }

    public function revokeSession(Request $request, array $params): never
    {
        $user = $this->requireUser();
        AuthService::make($this->app->db())->revokeSession((int) $user['id'], (int) $params['id']);
        $this->flashSuccess('Relace byla odhlášena.');
        $this->redirect('/user/profil');
    }

    public function logoutAll(): never
    {
        $user = $this->requireUser();
        AuthService::make($this->app->db())->logoutAll((int) $user['id'], $this->app->auth()->sessionRowId());
        $this->flashSuccess('Všechna ostatní zařízení byla odhlášena.');
        $this->redirect('/user/profil');
    }

    public function notifications(Request $request): never
    {
        $user = $this->requireUser();
        $this->app->db()->query(
            'INSERT INTO notification_preferences (user_id, email_reservations, email_reminders, email_membership, email_marketing, email_security)
             VALUES (:id, :a, :b, :c, :d, 1)
             ON DUPLICATE KEY UPDATE email_reservations = VALUES(email_reservations), email_reminders = VALUES(email_reminders),
                email_membership = VALUES(email_membership), email_marketing = VALUES(email_marketing)',
            [
                'id' => (int) $user['id'],
                'a' => $request->input('email_reservations') ? 1 : 0,
                'b' => $request->input('email_reminders') ? 1 : 0,
                'c' => $request->input('email_membership') ? 1 : 0,
                'd' => $request->input('email_marketing') ? 1 : 0,
            ]
        );
        $this->flashSuccess('Nastavení oznámení bylo uloženo.');
        $this->redirect('/user/profil');
    }

    public function export(): never
    {
        $user = $this->requireUser();
        $payload = [
            'user' => AuthService::make($this->app->db())->publicUser($user),
            'reservations' => ReservationService::make($this->app->db())->forUser((int) $user['id']),
            'memberships' => (new MembershipService($this->app->db()))->history((int) $user['id']),
            'payments' => (new PaymentService($this->app->db()))->forUser((int) $user['id']),
            'exported_at' => gmdate('c'),
        ];
        Response::download('privofit-export.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'application/json');
    }

    public function requestDeletion(): never
    {
        $user = $this->requireUser();
        $this->app->db()->update('users', ['deletion_requested_at' => \App\Support\Clock::utc()], 'id = :id', ['id' => (int) $user['id']]);
        $this->flashSuccess('Žádost o výmaz byla zaznamenána. Ozveme se podle lhůt ochrany osobních údajů.');
        $this->redirect('/user/profil');
    }

    public function showMfa(): never
    {
        $user = $this->requireUser();
        $auth = AuthService::make($this->app->db());
        $enabled = (int) $user['mfa_enabled'] === 1;
        $mfaRequired = AuthService::mfaRequiredFor($user);
        $setup = $enabled ? null : $auth->beginTotpSetup($user);
        $errors = Session::pull('errors', []);
        $recoveryLeft = $enabled ? $auth->remainingRecoveryCodes((int) $user['id']) : 0;
        if (is_array($setup)) {
            try {
                $setup['qr_svg'] = QrSvg::inline((string) $setup['otpauth']);
            } catch (\Throwable $e) {
                Logger::error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                error_log('PRIVOFIT QR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $setup['qr_svg'] = '';
                $setup['qr_error'] = $e->getMessage();
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->view('user/mfa', [
            'title' => 'Dvoufaktorové ověření',
            'enabled' => $enabled,
            'mfaRequired' => $mfaRequired,
            'mfaLock' => $mfaRequired && !$enabled,
            'recoveryLeft' => $recoveryLeft,
            'setup' => $setup,
            'errors' => $errors,
        ]);
    }

    public function confirmMfa(Request $request): never
    {
        $user = $this->requireUser();
        try {
            $codes = AuthService::make($this->app->db())->confirmTotp($user, (string) $request->input('code', ''));
            Session::set('recovery_codes', $codes);
            $this->flashSuccess('Dvoufaktorové ověření je aktivní. Ulož si záložní kódy.');
            $this->redirect('/user/zabezpeceni/mfa/kody');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/user/zabezpeceni/mfa');
        }
    }

    public function disableMfa(Request $request): never
    {
        $user = $this->requireUser();
        try {
            AuthService::make($this->app->db())->disableTotp($user, (string) $request->input('current_password', ''));
            $this->flashSuccess('Dvoufaktorové ověření bylo vypnuto.');
            $this->redirect('/user/profil');
        } catch (ValidationException $e) {
            Session::set('errors', $e->errors);
            $this->flashError($e->getMessage());
            $this->redirect('/user/zabezpeceni/mfa');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/user/zabezpeceni/mfa');
        }
    }

    public function recoveryCodes(): never
    {
        $user = $this->requireUser();
        $codes = Session::pull('recovery_codes', []);
        $codes = is_array($codes) ? $codes : [];
        $mfaLock = $codes !== [] && AuthService::mfaRequiredFor($user);
        $continue = '/user';
        if ($mfaLock) {
            $continue = is_admin_user($user) ? '/user/sprava' : '/user';
            $intended = Session::pull('intended', '');
            if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
                && !str_starts_with($intended, '/user/zabezpeceni/mfa')
                && !in_array($intended, ['/prihlaseni', '/odhlaseni', '/odhlaseno'], true)) {
                $continue = $intended;
            }
        }
        $this->view('user/mfa-codes', [
            'title' => 'Záložní kódy',
            'codes' => $codes,
            'mfaLock' => $mfaLock,
            'continueTo' => $continue,
        ]);
    }

    public function mfaQr(Request $request): never
    {
        $user = $this->requireUser();
        if ((int) $user['mfa_enabled'] === 1) {
            Response::send(self::qrUnavailableSvg(), 'image/svg+xml; charset=UTF-8', 404);
        }
        try {
            $uri = Session::get('mfa_setup_otpauth');
            if (!is_string($uri) || !str_starts_with($uri, 'otpauth://')) {
                $uri = AuthService::make($this->app->db())->totpProvisioningUri($user);
            }
            if ((string) $request->query('stahnout', '') !== '') {
                Response::download('privofit-2fa.png', QrSvg::png($uri), 'image/png');
            }
            Response::send(QrSvg::render($uri), 'image/svg+xml; charset=UTF-8');
        } catch (\Throwable $e) {
            Logger::error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            error_log('PRIVOFIT QR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::send(self::qrUnavailableSvg(), 'image/svg+xml; charset=UTF-8');
        }
    }

    private static function qrUnavailableSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" viewBox="0 0 220 220">'
            . '<rect width="220" height="220" fill="#fff"/>'
            . '<text x="110" y="110" text-anchor="middle" fill="#6b7280" font-size="14">QR není k dispozici</text>'
            . '</svg>';
    }
}
