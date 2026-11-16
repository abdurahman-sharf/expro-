<?php

namespace App\Filters;

use App\Libraries\Portal\PortalSession;
use App\Models\Employee;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class IsLoggedIn implements FilterInterface
{
    private const PASSWORD_PATHS = ['home/mustchangepassword', 'home/save', 'home/logout'];
    private const SETUP_PATHS    = ['setup', 'home/logout', 'accounting_settings/activation_impact', 'accounting_settings/activation_begin', 'accounting_settings/activation_step'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $employee = model(Employee::class);

        if (! $employee->is_logged_in() || (new PortalSession())->holdsPortal()) {
            if ($request->isAJAX()) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Session expired. Please log in again.',
                    ]);
            }

            throw new RedirectException('login');
        }

        $path = strtolower(trim($request->getUri()->getRoutePath(), '/ '));

        if (! in_array($path, self::PASSWORD_PATHS, true) && $employee->mustChangePassword()) {
            if ($request->isAJAX()) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON(['success' => false, 'message' => lang('Employees.must_change_password')]);
            }

            throw new RedirectException('home/mustChangePassword');
        }

        if (! $this->isSetupPath($path) && $employee->setupPending()) {
            if ($request->isAJAX()) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON(['success' => false, 'message' => lang('Setup.must_finish')]);
            }

            throw new RedirectException('setup');
        }

        return null;
    }

    private function isSetupPath(string $path): bool
    {
        return in_array($path, self::SETUP_PATHS, true) || str_starts_with($path, 'setup/');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
