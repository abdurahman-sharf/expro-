<?php

namespace App\Controllers;

use App\Libraries\Security\PasswordPolicy;
use App\Libraries\Setup\InstallState;
use App\Libraries\Setup\OwnerClaim;
use App\Libraries\Setup\OwnerClaimException;
use App\Models\Employee;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class OwnerSetup extends BaseController
{
    private const FIELDS = ['code', 'first_name', 'last_name', 'username', 'email', 'password', 'password_confirm'];

    public function postClaim(): RedirectResponse|ResponseInterface|string
    {
        if (! (new InstallState())->needsOwner()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $input = [];

        foreach (self::FIELDS as $field) {
            $value = $this->request->getPost($field);

            if ($value !== null && ! is_string($value)) {
                return $this->response->setStatusCode(400)->setBody('Bad request.');
            }

            $input[$field] = (string) $value;
        }

        try {
            (new OwnerClaim())->claim($input, $this->request->getIPAddress());
        } catch (OwnerClaimException $failure) {
            return $this->response->setStatusCode($failure->key === 'closed' ? 404 : 422)->setBody(view('setup/claim', [
                'error'    => $this->messageOf($failure),
                'errorKey' => $failure->key,
                'old'      => array_diff_key($input, array_flip(['password', 'password_confirm', 'code'])),
            ]));
        }

        model(Employee::class)->login($input['username'], $input['password']);

        return redirect()->to('home');
    }

    private function messageOf(OwnerClaimException $failure): string
    {
        if ($failure->key === 'password_policy') {
            return lang((string) ($failure->context['line'] ?? 'Employees.password_minlength'), [PasswordPolicy::MIN_LENGTH]);
        }

        return lang('Owner_setup.error_' . $failure->key);
    }
}
