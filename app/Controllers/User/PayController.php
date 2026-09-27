<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Billing\CheckoutService;

final class PayController extends Controller
{
    public function show(Request $request, array $params): never
    {
        $user = $this->requireUser();
        $checkout = CheckoutService::make($this->app->db());
        try {
            $page = $checkout->page((string) ($params['id'] ?? ''), $user);
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect('/user');
        }
        if (!empty($page['paid'])) {
            $dest = $checkout->destination($page['payment']);
            $this->flashSuccess((string) $dest['doneMessage']);
            $this->redirect((string) $dest['doneUrl']);
        }
        $this->view('user/pay', [
            'title' => 'Platba',
            'page' => $page,
            'pageScripts' => ['js/pay.js'],
        ]);
    }

    public function confirm(Request $request): never
    {
        $user = $this->requireUser();
        try {
            $result = CheckoutService::make($this->app->db())->confirm(
                $user,
                (string) $request->input('platba', ''),
                (string) $request->input('confirmation_token', ''),
                (int) $request->input('shown_minor', 0),
                $this->app,
            );
        } catch (HttpException $e) {
            $this->jsonError($e->getMessage(), $e->status);
        }
        $this->jsonOk($result);
    }

    public function returned(Request $request): never
    {
        $user = $this->requireUser();
        $publicId = trim((string) $request->query('platba', ''));
        $back = $publicId !== '' ? '/user/platba/' . rawurlencode($publicId) : '/user';
        if ((string) $request->query('redirect_status', '') === 'failed') {
            if ($publicId !== '') {
                CheckoutService::make($this->app->db())->noteMembershipDeclined($publicId, $user);
            }
            $this->flashError('Platba se nedokončila. Můžeš to zkusit znovu.');
            $this->redirect($back);
        }
        $checkout = CheckoutService::make($this->app->db());
        try {
            $payment = $checkout->fulfillIntent(trim((string) $request->query('payment_intent', '')), $user);
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
            $this->redirect($back);
        }
        $dest = $checkout->destination($payment);
        $this->flashSuccess((string) $dest['doneMessage']);
        $this->redirect((string) $dest['doneUrl']);
    }
}
