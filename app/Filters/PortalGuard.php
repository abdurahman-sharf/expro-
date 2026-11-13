<?php

namespace App\Filters;

use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalSession;
use App\Libraries\Portal\PortalSettings;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Stands in front of every route of the portal. While the portal is switched off every route is a page that
 * does not exist. A session that holds an employee is refused, the sign-in page apart nothing opens without
 * a customer who is signed in, a session that is idle, too old, of an account that was disabled or whose
 * password was changed, or of a customer that was deleted ends, and an account whose password must be
 * changed reaches nothing but the page that changes it.
 */
final class PortalGuard implements FilterInterface
{
    private const OPEN_PATHS   = ['portal/login'];
    private const CHANGE_PATHS = ['portal/password', 'portal/logout'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! (new PortalSettings())->isEnabled()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $session = new PortalSession();

        if ($session->holdsEmployee()) {
            return service('response')->setStatusCode(403)->setBody(lang('Portal.refused_employee'));
        }

        $path = strtolower(trim($request->getUri()->getRoutePath(), '/ '));

        if (in_array($path, self::OPEN_PATHS, true)) {
            return null;
        }

        $account = $this->liveAccount($session);

        if ($account === null) {
            $session->end();

            return $this->toSignIn($request);
        }

        if ((int) $account->must_change === 1 && ! in_array($path, self::CHANGE_PATHS, true)) {
            return redirect()->to(site_url('portal/password'));
        }

        $session->touch();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function liveAccount(PortalSession $session): ?object
    {
        $accountId = $session->accountId();

        if ($accountId === null || $session->expired()) {
            return null;
        }

        $account = (new PortalAccounts())->live($accountId, $session->credentialVersion());

        return $account !== null && (int) $account->customer_id === $session->customerId() ? $account : null;
    }

    private function toSignIn(RequestInterface $request): ResponseInterface
    {
        if ($request->isAJAX()) {
            return service('response')->setStatusCode(401)->setJSON(['success' => false, 'message' => lang('Portal.session_ended')]);
        }

        return redirect()->to(site_url('portal/login'));
    }
}
