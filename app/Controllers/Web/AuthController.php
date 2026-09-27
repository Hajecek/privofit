<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Response;
use App\Services\Auth\AppleOAuth;
use App\Services\Auth\AuthService;
use App\Services\Auth\GoogleOAuth;
use App\Services\Auth\MfaRequiredException;
use App\Services\Auth\MfaSetupRequiredException;
use App\Services\Auth\ValidationException;

final class AuthController extends Controller
{
    private function authService(): AuthService
    {
        return AuthService::make($this->app->db());
    }

    public function showRegister(): never
    {
        $this->view('auth/register', [
            'title' => 'Registrace | PRIVOFIT',
            'page' => 'register',
            'bodyClass' => 'standalone-registration',
            'errors' => Session::pull('errors', []),
        ], 'layouts/brand');
    }

    public function register(Request $request): never
    {
        $this->rememberOld($request);
        try {
            $this->authService()->register($request->all(), $request, $request->file('avatar'));
        } catch (ValidationException $e) {
            Session::set('errors', $e->errors);
            $this->flashError($e->getMessage());
            $this->redirect('/registrace');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/registrace');
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/registrace');
        }
        Session::forget('_old');
        $this->flashSuccess('Účet byl vytvořen. Poslali jsme vám ověřovací e-mail.');
        $this->redirect('/prihlaseni');
    }

    public function showLogin(): never
    {
        $this->view('auth/login', [
            'title' => 'Přihlášení | PRIVOFIT',
            'page' => 'login',
            'bodyClass' => 'standalone-login',
            'mfa' => false,
        ], 'layouts/brand');
    }

    public function login(Request $request): never
    {
        try {
            $pendingId = Session::get('mfa_pending_user_id');
            if (is_numeric($pendingId) && $request->input('totp')) {
                $user = $this->authService()->completeMfaLogin((int) $pendingId, (string) $request->input('totp'), $request, (bool) Session::get('mfa_pending_remember'));
                Session::forget('mfa_pending_user_id');
                Session::forget('mfa_pending_remember');
            } else {
                $user = $this->authService()->login(
                    (string) ($request->input('identifier') ?: $request->input('email', '')),
                    (string) $request->input('password', ''),
                    $request,
                    (bool) $request->input('remember'),
                    $request->input('totp') !== null ? (string) $request->input('totp') : null
                );
            }
            $this->app->auth()->setUser($user, (int) Session::get('auth_session_id'));
        } catch (MfaRequiredException $e) {
            Session::set('mfa_pending_user_id', (int) $e->user['id']);
            Session::set('mfa_pending_remember', (bool) $request->input('remember'));
            $this->showMfaChallenge($e->user, (bool) $request->input('remember'));
        } catch (MfaSetupRequiredException) {
            $this->redirect('/user/zabezpeceni/mfa');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/prihlaseni');
        }
        $this->redirectAfterLogin($user);
    }

    public function googleStart(Request $request): never
    {
        $mobile = $request->query('mobile') === '1';
        if (!GoogleOAuth::configured()) {
            if ($mobile) {
                $this->redirectMobileError('Přihlášení přes Google ještě není nastavené.');
            }
            $this->flashError('Přihlášení přes Google ještě není nastavené.');
            $this->redirect('/prihlaseni');
        }
        $state = bin2hex(random_bytes(16));
        Session::set('oauth_google', ['state' => $state, 'at' => time(), 'mobile' => $mobile]);
        Response::redirect(GoogleOAuth::authorizationUrl($state, $this->app->absoluteUrl('/prihlaseni/google/callback')));
    }

    public function googleCallback(Request $request): never
    {
        $saved = Session::pull('oauth_google');
        $mobile = is_array($saved) && !empty($saved['mobile']);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if ((string) $request->query('error', '') !== '') {
            if ($mobile) {
                $this->redirectMobileError('Přihlášení přes Google bylo zrušené.');
            }
            $this->flashError('Přihlášení přes Google bylo zrušené.');
            $this->redirect('/prihlaseni');
        }
        $fresh = is_array($saved)
            && isset($saved['state'])
            && hash_equals((string) $saved['state'], $state)
            && time() - (int) ($saved['at'] ?? 0) <= 600;
        if (!$fresh || $code === '') {
            if ($mobile) {
                $this->redirectMobileError('Přihlášení přes Google vypršelo. Zkus to znovu.');
            }
            $this->flashError('Přihlášení přes Google vypršelo. Zkus to znovu.');
            $this->redirect('/prihlaseni');
        }
        try {
            $profile = GoogleOAuth::profile($code, $this->app->absoluteUrl('/prihlaseni/google/callback'));
            if ($mobile) {
                $user = $this->authService()->loginFromAppWithOAuth('google', $profile, $request);
                $ticket = $this->authService()->issueMobileLoginTicket($user);
                Response::redirect('privofit://oauth?ticket=' . rawurlencode($ticket));
            }
            $user = $this->authService()->loginWithGoogle($profile, $request);
            $this->app->auth()->setUser($user, (int) Session::get('auth_session_id'));
        } catch (MfaRequiredException $e) {
            if ($mobile) {
                $this->redirectMobileError('Účet má zapnuté ověření kódem. Přihlas se e-mailem a heslem.');
            }
            Session::set('mfa_pending_user_id', (int) $e->user['id']);
            Session::set('mfa_pending_remember', false);
            $this->showMfaChallenge($e->user, false);
        } catch (HttpException $e) {
            if ($mobile) {
                $this->redirectMobileError($e->getMessage());
            }
            $this->flashError($e->getMessage());
            $this->redirect('/prihlaseni');
        }
        if ($mobile) {
            $this->redirectMobileError('Přihlášení přes Google se nepovedlo. Zkus to znovu.');
        }
        $this->redirectAfterLogin($user);
    }

    private function redirectMobileError(string $message): never
    {
        Response::redirect('privofit://oauth?error=' . rawurlencode($message));
    }

    public function appleStart(): never
    {
        if (!AppleOAuth::configured()) {
            $this->flashError('Přihlášení přes Apple ještě není nastavené.');
            $this->redirect('/prihlaseni');
        }
        $issued = AppleOAuth::issueState();
        Response::redirect(AppleOAuth::authorizationUrl(
            $issued['state'],
            $issued['nonce'],
            $this->app->absoluteUrl('/prihlaseni/apple/callback')
        ));
    }

    public function appleCallback(Request $request): never
    {
        if ((string) $request->input('error', '') !== '') {
            $this->flashError('Přihlášení přes Apple bylo zrušené.');
            $this->redirect('/prihlaseni');
        }
        $state = (string) $request->input('state', '');
        $code = (string) $request->input('code', '');
        $idToken = (string) $request->input('id_token', '');
        $nonce = AppleOAuth::consumeNonce($state);
        if ($nonce === null || $code === '' || $idToken === '') {
            $this->flashError('Přihlášení přes Apple vypršelo. Zkus to znovu.');
            $this->redirect('/prihlaseni');
        }
        $userJson = $request->input('user');
        try {
            $profile = AppleOAuth::profile(
                $code,
                $idToken,
                $this->app->absoluteUrl('/prihlaseni/apple/callback'),
                $nonce,
                is_string($userJson) ? $userJson : null
            );
            $user = $this->authService()->loginWithApple($profile, $request);
            $this->app->auth()->setUser($user, (int) Session::get('auth_session_id'));
        } catch (MfaRequiredException $e) {
            Session::set('mfa_pending_user_id', (int) $e->user['id']);
            Session::set('mfa_pending_remember', false);
            $this->showMfaChallenge($e->user, false);
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/prihlaseni');
        }
        $this->redirectAfterLogin($user);
    }

    /** @param array<string, mixed> $user */
    private function redirectAfterLogin(array $user): never
    {
        if (AuthService::mfaRequiredFor($user) && (int) ($user['mfa_enabled'] ?? 0) !== 1) {
            $this->redirect('/user/zabezpeceni/mfa');
        }
        $intended = Session::pull('intended', '/user');
        $this->redirect(is_string($intended) ? $intended : '/user');
    }

    /** @param array<string, mixed> $user */
    private function showMfaChallenge(array $user, bool $remember): never
    {
        $this->view('auth/login', [
            'title' => 'Ověření přihlášení | PRIVOFIT',
            'page' => 'login',
            'bodyClass' => 'standalone-login',
            'mfa' => true,
            'email' => $user['email'] ?? '',
            'remember' => $remember,
        ], 'layouts/brand');
    }

    public function signedOut(): never
    {
        $minutes = max(5, (int) config('security.session.idle_minutes', 20));
        $reason = (string) Session::pull('logged_out_reason', 'idle');
        $message = (string) Session::pull('logged_out_message', '');
        $this->view('auth/signed-out', [
            'title' => 'Odhlášení | PRIVOFIT',
            'page' => 'signed-out',
            'bodyClass' => 'standalone-signed-out',
            'idle_minutes' => $minutes,
            'logout_reason' => $reason,
            'logout_message' => $message,
        ], 'layouts/brand');
    }

    public function logout(Request $request): never
    {
        $this->authService()->logout($request);
        $this->flashSuccess('Byli jste odhlášeni.');
        $this->redirect('/');
    }

    public function showForgot(): never
    {
        $this->view('auth/forgot', ['title' => 'Zapomenuté heslo | PRIVOFIT', 'page' => 'login'], 'layouts/brand');
    }

    public function forgot(Request $request): never
    {
        try {
            $this->authService()->forgotPassword((string) $request->input('email', ''), $request);
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/zapomenute-heslo');
        }
        $this->flashSuccess('Pokud účet existuje, poslali jsme pokyny k obnovení hesla.');
        $this->redirect('/prihlaseni');
    }

    public function showReset(Request $request): never
    {
        $this->view('auth/reset', [
            'title' => 'Nové heslo | PRIVOFIT',
            'page' => 'login',
            'token' => (string) $request->query('token', ''),
        ], 'layouts/brand');
    }

    public function reset(Request $request): never
    {
        try {
            $this->authService()->resetPassword(
                (string) $request->input('token', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', '')
            );
        } catch (ValidationException $e) {
            Session::set('errors', $e->errors);
            $this->flashError($e->getMessage());
            $this->redirect('/obnoveni-hesla?token=' . urlencode((string) $request->input('token', '')));
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/zapomenute-heslo');
        }
        $this->flashSuccess('Heslo bylo změněno. Přihlaste se novým heslem.');
        $this->redirect('/prihlaseni');
    }

    public function verifyEmail(Request $request): never
    {
        try {
            $this->authService()->verifyEmail((string) $request->query('token', ''));
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/prihlaseni');
        }
        $this->flashSuccess('E-mail byl ověřen. Nyní se můžete přihlásit.');
        $this->redirect('/prihlaseni');
    }

    public function confirmEmailChange(Request $request): never
    {
        $next = $this->app->auth()->check() ? '/user/profil' : '/prihlaseni';
        try {
            $this->authService()->confirmEmailChange((string) $request->query('token', ''));
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect($next);
        }
        $this->flashSuccess($next === '/prihlaseni' ? 'E-mailová adresa byla změněna. Přihlas se.' : 'E-mailová adresa byla změněna.');
        $this->redirect($next);
    }
}
