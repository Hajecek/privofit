<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

final class AuthMiddleware
{
    public function handle(Request $request, Application $app): void
    {
        if ($app->auth()->check()) {
            $user = $app->auth()->user();
            $required = (array) config('security.mfa_required_roles', []);
            $path = $request->path();
            if ($user && in_array($user['role'], $required, true) && (int) $user['mfa_enabled'] !== 1) {
                $mfaSetup = str_starts_with($path, '/user/zabezpeceni/mfa') || $path === '/odhlaseni';
                if (!$mfaSetup && !str_starts_with($path, '/api/v1/auth/')) {
                    if ($request->wantsJson()) {
                        throw new HttpException(403, 'Pro tento účet je nutné nastavit MFA.');
                    }
                    header('Location: ' . $app->url('/user/zabezpeceni/mfa'));
                    exit;
                }
            }
            return;
        }
        $idle = Session::get('logged_out_reason') === 'idle';
        $forced = in_array((string) Session::get('logged_out_reason'), ['blocked', 'deleted'], true);
        if ($request->wantsJson()) {
            $message = match ((string) Session::get('logged_out_reason')) {
                'blocked', 'deleted' => (string) (Session::get('logged_out_message') ?: 'Účet už není dostupný.'),
                'idle' => 'Odhlásili jsme tě z důvodu bezpečnosti.',
                default => 'Nejste přihlášeni.',
            };
            throw new HttpException(401, $message);
        }
        if ($idle || $forced) {
            header('Location: ' . $app->url('/odhlaseno'));
            exit;
        }
        $intended = $request->path();
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($query !== '') {
            $intended .= '?' . $query;
        }
        Session::set('intended', $intended);
        Session::flash('error', 'Pro pokračování se prosím přihlaste.');
        header('Location: ' . $app->url('/prihlaseni'));
        exit;
    }
}
