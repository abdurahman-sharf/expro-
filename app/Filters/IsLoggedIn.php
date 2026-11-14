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

    public function before(RequestInterface $request, $arguments = null)
    {
        $employee = model(Employee::class);

        if (!$employee->is_logged_in() || (new PortalSession())->holdsPortal()) {
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

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
