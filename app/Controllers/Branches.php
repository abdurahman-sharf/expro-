<?php

namespace App\Controllers;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class Branches extends Secure_Controller
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MESSAGE_ARGUMENTS = ['code'];

    private BranchGuard $guard;
    private BranchService $branches;

    public function __construct()
    {
        parent::__construct('branches');

        $this->guard    = new BranchGuard(null, $this->employee);
        $this->branches = new BranchService(null, $this->guard);
    }

    public function getIndex(): string
    {
        return view('branches/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_create'    => $this->canCreate(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->branches->all($this->employeeId());

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (object $branch): bool => str_contains(mb_strtolower($branch->code . ' ' . $branch->name), $term)));
        }

        return $this->response->setJSON([
            'total' => count($rows),
            'rows'  => array_map(fn (object $branch): array => $this->row($branch), array_slice($rows, $offset, $limit)),
        ]);
    }

    public function getRow(int $branchId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->visibleBranch($branchId)));
    }

    public function getView(int $branchId = NEW_ENTRY): string
    {
        if (! $this->canManage()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isNew = $branchId === NEW_ENTRY;

        if ($isNew && ! $this->canCreate()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $branch = $isNew ? null : $this->visibleBranch($branchId);

        return view('branches/form', [
            'branch'         => $branch,
            'suggested_code' => $isNew ? $this->branches->suggestCode() : '',
            'locations'      => $this->guard->isUnrestricted($this->employeeId()) ? $this->branches->locations() : [],
        ]);
    }

    public function postSave(int $branchId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($branchId): array {
            $input = [
                'code'             => (string) $this->request->getPost('code'),
                'name'             => (string) $this->request->getPost('name'),
                'address'          => (string) $this->request->getPost('address'),
                'phone'            => (string) $this->request->getPost('phone'),
                'tax_registration' => (string) $this->request->getPost('tax_registration'),
                'opened_on'        => (string) $this->request->getPost('opened_on'),
            ];

            $employeeId = $this->employeeId();

            if ($branchId === NEW_ENTRY) {
                $id      = $this->branches->create($input, $employeeId);
                $message = lang('Branches.successful_adding');
            } else {
                $this->branches->update($branchId, $input, $employeeId);
                $this->applyActive($branchId, $this->request->getPost('active'));

                $id      = $branchId;
                $message = lang('Branches.successful_updating');
            }

            if ($this->request->getPost('locations_present') !== null && $this->guard->isUnrestricted($employeeId)) {
                $locations = $this->request->getPost('locations');

                $this->branches->assignLocations($id, is_array($locations) ? array_map('intval', $locations) : [], $employeeId);
            }

            return ['success' => true, 'message' => $message, 'id' => $id];
        });
    }

    public function postDeactivate(int $branchId): ResponseInterface
    {
        return $this->attempt(function () use ($branchId): array {
            $this->branches->deactivate($branchId, $this->employeeId());

            return ['success' => true, 'message' => lang('Branches.successful_deactivating'), 'id' => $branchId];
        });
    }

    public function postActivate(int $branchId): ResponseInterface
    {
        return $this->attempt(function () use ($branchId): array {
            $this->branches->activate($branchId, $this->employeeId());

            return ['success' => true, 'message' => lang('Branches.successful_activating'), 'id' => $branchId];
        });
    }

    public function postDeleteBranch(int $branchId): ResponseInterface
    {
        return $this->attempt(function () use ($branchId): array {
            $this->branches->delete($branchId, $this->employeeId());

            return ['success' => true, 'message' => lang('Branches.successful_deleting'), 'id' => $branchId];
        });
    }

    private function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    private function canManage(): bool
    {
        return $this->employee->has_grant(BranchGuard::BRANCHES_MANAGE, $this->employeeId());
    }

    private function canCreate(): bool
    {
        return $this->canManage() && $this->guard->isUnrestricted($this->employeeId());
    }

    /**
     * A branch outside the employee's scope reads as missing.
     */
    private function visibleBranch(int $branchId): object
    {
        foreach ($this->branches->all($this->employeeId()) as $branch) {
            if ((int) $branch->branch_id === $branchId) {
                return $branch;
            }
        }

        throw PageNotFoundException::forPageNotFound();
    }

    private function applyActive(int $branchId, mixed $wanted): void
    {
        $branch = $this->branches->find($branchId);

        if ($branch === null || $wanted === null) {
            return;
        }

        if ((int) $wanted === 0 && (int) $branch->active === 1) {
            $this->branches->deactivate($branchId, $this->employeeId());
        } elseif ((int) $wanted === 1 && (int) $branch->active === 0) {
            $this->branches->activate($branchId, $this->employeeId());
        }
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (BranchException $exception) {
            $arguments = [];

            foreach (self::MESSAGE_ARGUMENTS as $name) {
                if (array_key_exists($name, $exception->context)) {
                    $arguments[] = (string) $exception->context[$name];
                }
            }

            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Branches.error_' . $exception->error->value, $arguments),
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
            ['code' => lang('Branches.code'), 'sortable' => false],
            ['name'           => lang('Branches.name'), 'sortable' => false, 'escape' => false],
            ['location_count' => lang('Branches.locations'), 'sortable' => false],
            ['employee_count' => lang('Branches.employees'), 'sortable' => false],
            ['journal_count'  => lang('Branches.journals'), 'sortable' => false],
            ['status'         => lang('Branches.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $branch): array
    {
        $id        = (int) $branch->branch_id;
        $isDefault = (int) $branch->is_default === 1;
        $name      = esc($branch->name) . ($isDefault ? ' <span class="badge text-bg-primary">' . esc(lang('Branches.default')) . '</span>' : '');

        return [
            'branch_id'      => $id,
            'code'           => $branch->code,
            'name'           => $name,
            'location_count' => (int) $branch->location_count,
            'employee_count' => (int) $branch->employee_count,
            'journal_count'  => (int) $branch->journal_count,
            'status'         => '<span class="badge ' . ((int) $branch->active === 1 ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang('Branches.' . ((int) $branch->active === 1 ? 'active' : 'inactive'))) . '</span>',
            'edit'           => $this->actions($branch),
        ];
    }

    private function actions(object $branch): string
    {
        if (! $this->canManage()) {
            return '';
        }

        $id   = (int) $branch->branch_id;
        $html = anchor(
            "branches/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Branches.update')],
        );

        if ((int) $branch->is_default === 1) {
            return $html;
        }

        $active = (int) $branch->active === 1;
        $toggle = $active ? 'deactivate' : 'activate';
        $icon   = $active ? 'bi-slash-circle' : 'bi-check-circle';
        $label  = esc(lang('Branches.' . $toggle), 'attr');
        $html .= '<button type="button" class="btn btn-link p-0 branch-action" data-branch-action="' . $toggle . '" data-branch-id="' . $id . '" title="' . $label . '" aria-label="' . $label . '"><span class="bi ' . $icon . '" aria-hidden="true"></span></button>';

        $unused = (int) $branch->location_count === 0 && (int) $branch->employee_count === 0 && (int) $branch->journal_count === 0;

        if ($unused && $this->guard->isUnrestricted($this->employeeId())) {
            $delete = esc(lang('Common.delete'), 'attr');
            $html .= '<button type="button" class="btn btn-link p-0 branch-action text-danger" data-branch-action="delete" data-branch-id="' . $id . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
        }

        return $html;
    }
}
