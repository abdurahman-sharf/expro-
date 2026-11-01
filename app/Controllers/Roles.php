<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Roles\PermissionCatalog;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RoleException;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class Roles extends Secure_Controller
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MESSAGE_ARGUMENTS = ['permission', 'name'];

    private RoleService $roles;

    public function __construct()
    {
        parent::__construct('roles');

        $this->roles = new RoleService(null, $this->employee);
    }

    public function getIndex(): string
    {
        return view('roles/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_manage'    => $this->canManage(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->roles->all();

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (object $role): bool => str_contains(mb_strtolower($role->name . ' ' . ($role->description ?? '')), $term)));
        }

        return $this->response->setJSON([
            'total' => count($rows),
            'rows'  => array_map(fn (object $role): array => $this->row($role), array_slice($rows, $offset, $limit)),
        ]);
    }

    public function getRow(int $roleId): ResponseInterface
    {
        foreach ($this->roles->all() as $role) {
            if ((int) $role->role_id === $roleId) {
                return $this->response->setJSON($this->row($role));
            }
        }

        throw PageNotFoundException::forPageNotFound();
    }

    public function getView(int $roleId = NEW_ENTRY): string
    {
        if (! $this->canManage()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $role = $roleId === NEW_ENTRY ? null : $this->roles->find($roleId);

        if ($roleId !== NEW_ENTRY && $role === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('roles/form', [
            'role'      => $role,
            'held'      => $role === null ? [] : $this->roles->permissionsOf($roleId),
            'modules'   => (new PermissionCatalog())->modules(),
            'is_admin'  => $this->employee->isAdmin($this->employeeId()),
            'my_grants' => array_column($this->employee->get_employee_grants($this->employeeId()), 'permission_id'),
        ]);
    }

    public function postSave(int $roleId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($roleId): array {
            $permissions = $this->request->getPost('permissions');
            $input       = [
                'name'         => (string) $this->request->getPost('name'),
                'description'  => (string) $this->request->getPost('description'),
                'active'       => $this->request->getPost('active') ?? '1',
                'record_scope' => (string) ($this->request->getPost('record_scope') ?? RecordScope::ALL),
                'permissions'  => is_array($permissions) ? array_values($permissions) : [],
            ];

            if ($roleId === NEW_ENTRY) {
                $id      = $this->roles->create($input, $this->employeeId());
                $message = lang('Roles.successful_adding');
            } else {
                $this->roles->update($roleId, $input, $this->employeeId());

                $id      = $roleId;
                $message = lang('Roles.successful_updating');
            }

            return ['success' => true, 'message' => $message, 'id' => $id];
        });
    }

    public function postDeleteRole(int $roleId): ResponseInterface
    {
        return $this->attempt(function () use ($roleId): array {
            $this->roles->delete($roleId, $this->employeeId());

            return ['success' => true, 'message' => lang('Roles.successful_deleting'), 'id' => $roleId];
        });
    }

    private function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    private function canManage(): bool
    {
        return $this->employee->has_grant(AccountingGuard::ROLES_MANAGE, $this->employeeId());
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (RoleException $exception) {
            $arguments = [];

            foreach (self::MESSAGE_ARGUMENTS as $name) {
                if (array_key_exists($name, $exception->context)) {
                    $arguments[] = (string) $exception->context[$name];
                }
            }

            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Roles.error_' . $exception->error->value, $arguments),
                'id'      => NEW_ENTRY,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['name' => lang('Roles.name'), 'sortable' => false, 'escape' => false],
            ['record_scope'     => lang('Roles.record_scope'), 'sortable' => false],
            ['permission_count' => lang('Roles.permissions'), 'sortable' => false],
            ['employee_count'   => lang('Roles.employees'), 'sortable' => false],
            ['status'           => lang('Roles.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $role): array
    {
        $id          = (int) $role->role_id;
        $description = trim((string) ($role->description ?? ''));
        $name        = esc($role->name) . ($description === '' ? '' : '<br><span class="text-secondary small">' . esc($description) . '</span>');

        return [
            'role_id'          => $id,
            'name'             => $name,
            'record_scope'     => lang('Roles.scope_' . ($role->record_scope === RecordScope::OWN ? 'own' : 'all')),
            'permission_count' => (int) $role->permission_count,
            'employee_count'   => (int) $role->employee_count,
            'status'           => '<span class="badge ' . ((int) $role->active === 1 ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang('Roles.' . ((int) $role->active === 1 ? 'active' : 'inactive'))) . '</span>',
            'edit'             => $this->actions($role),
        ];
    }

    private function actions(object $role): string
    {
        if (! $this->canManage()) {
            return '';
        }

        $id   = (int) $role->role_id;
        $html = anchor(
            "roles/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Roles.update')],
        );

        if ((int) $role->employee_count === 0) {
            $delete = esc(lang('Common.delete'), 'attr');
            $html .= '<button type="button" class="btn btn-link p-0 role-action text-danger" data-role-id="' . $id . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
        }

        return $html;
    }
}
