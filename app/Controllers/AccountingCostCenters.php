<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingCostCenters extends AccountingController
{
    private const MAX_DEPTH_CLASS = 6;

    private CostCenterService $costCenters;

    public function __construct()
    {
        parent::__construct('accounting_cost_centers');

        $this->costCenters = new CostCenterService();
    }

    public function getIndex(): string
    {
        return view('accounting_cost_centers/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_manage'    => $this->can(AccountingGuard::COST_CENTERS_MANAGE),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->ordered($this->costCenters->all());

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (array $item): bool => str_contains(mb_strtolower($item['center']->code . ' ' . $item['center']->name), $term)));
        }

        $names = [];

        foreach ($this->costCenters->all() as $center) {
            $names[(int) $center->cost_center_id] = $center->name;
        }

        $data = [];

        foreach (array_slice($rows, $offset, $limit) as $item) {
            $data[] = $this->row($item['center'], $item['depth'], $names);
        }

        return $this->response->setJSON(['total' => count($rows), 'rows' => $data]);
    }

    public function getRow(int $costCenterId): ResponseInterface
    {
        $names = [];
        $found = null;

        foreach ($this->ordered($this->costCenters->all()) as $item) {
            $names[(int) $item['center']->cost_center_id] = $item['center']->name;

            if ((int) $item['center']->cost_center_id === $costCenterId) {
                $found = $item;
            }
        }

        if ($found === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($found['center'], $found['depth'], $names));
    }

    public function getView(int $costCenterId = NEW_ENTRY): string
    {
        $center = $costCenterId === NEW_ENTRY ? null : $this->costCenters->find($costCenterId);

        if ($costCenterId !== NEW_ENTRY && $center === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $excluded = $center === null ? [] : $this->withDescendants($costCenterId);
        $parents  = array_values(array_filter(
            $this->costCenters->all(),
            static fn (object $item): bool => (int) $item->active === 1 && ! in_array((int) $item->cost_center_id, $excluded, true),
        ));

        return view('accounting_cost_centers/form', [
            'center'         => $center,
            'parents'        => $parents,
            'suggested_code' => $center === null ? $this->costCenters->suggestCode() : '',
        ]);
    }

    public function postSave(int $costCenterId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($costCenterId): array {
            $input = [
                'name'      => (string) $this->request->getPost('name'),
                'code'      => (string) $this->request->getPost('code'),
                'parent_id' => (int) $this->request->getPost('parent_id') ?: null,
            ];

            if ($costCenterId === NEW_ENTRY) {
                $id = $this->costCenters->create($input, $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_cost_centers.successful_adding'), 'id' => $id];
            }

            $this->costCenters->update($costCenterId, $input, $this->employeeId());

            $wantActive = $this->request->getPost('active');
            $center     = $this->costCenters->find($costCenterId);

            if ($center !== null && $wantActive !== null) {
                if ((int) $wantActive === 0 && (int) $center->active === 1) {
                    $this->costCenters->deactivate($costCenterId, $this->employeeId());
                } elseif ((int) $wantActive === 1 && (int) $center->active === 0) {
                    $this->costCenters->activate($costCenterId, $this->employeeId());
                }
            }

            return ['success' => true, 'message' => lang('Accounting_cost_centers.successful_updating'), 'id' => $costCenterId];
        });
    }

    public function postDeactivate(int $costCenterId): ResponseInterface
    {
        return $this->attempt(function () use ($costCenterId): array {
            $this->costCenters->deactivate($costCenterId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cost_centers.successful_deactivating'), 'id' => $costCenterId];
        });
    }

    public function postActivate(int $costCenterId): ResponseInterface
    {
        return $this->attempt(function () use ($costCenterId): array {
            $this->costCenters->activate($costCenterId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cost_centers.successful_activating'), 'id' => $costCenterId];
        });
    }

    public function postDeleteCostCenter(int $costCenterId): ResponseInterface
    {
        return $this->attempt(function () use ($costCenterId): array {
            $this->costCenters->delete($costCenterId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cost_centers.successful_deleting'), 'id' => $costCenterId];
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function headers(): array
    {
        return [
            ['code' => lang('Accounting_cost_centers.code'), 'sortable' => false],
            ['name'        => lang('Accounting_cost_centers.name'), 'sortable' => false, 'escape' => false],
            ['parent_name' => lang('Accounting_cost_centers.parent'), 'sortable' => false],
            ['status'      => lang('Accounting.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * Depth-first order of the cost center tree.
     *
     * @param list<object> $centers
     *
     * @return list<array{center: object, depth: int}>
     */
    private function ordered(array $centers): array
    {
        $byParent = [];

        foreach ($centers as $center) {
            $byParent[$center->parent_id === null ? 0 : (int) $center->parent_id][] = $center;
        }

        $result = [];
        $walk   = static function (int $parentId, int $depth) use (&$walk, &$result, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $center) {
                $result[] = ['center' => $center, 'depth' => $depth];
                $walk((int) $center->cost_center_id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $result;
    }

    /**
     * @return list<int>
     */
    private function withDescendants(int $costCenterId): array
    {
        $ids = [$costCenterId];

        foreach ($this->ordered($this->costCenters->all()) as $item) {
            if ($item['center']->parent_id !== null && in_array((int) $item['center']->parent_id, $ids, true)) {
                $ids[] = (int) $item['center']->cost_center_id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<string, mixed>
     */
    private function row(object $center, int $depth, array $names): array
    {
        $id     = (int) $center->cost_center_id;
        $active = (int) $center->active === 1;

        return [
            'cost_center_id' => $id,
            'code'           => $center->code,
            'name'           => '<span class="acc-depth acc-depth-' . min($depth, self::MAX_DEPTH_CLASS) . '">' . esc($center->name) . '</span>',
            'parent_name'    => $center->parent_id === null ? '' : ($names[(int) $center->parent_id] ?? ''),
            'status'         => $this->badge($active ? 'active' : 'inactive'),
            'edit'           => $this->actions($center),
        ];
    }

    private function actions(object $center): string
    {
        if (! $this->can(AccountingGuard::COST_CENTERS_MANAGE)) {
            return '';
        }

        $id     = (int) $center->cost_center_id;
        $active = (int) $center->active === 1;
        $html   = anchor(
            "accounting_cost_centers/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_cost_centers.update')],
        );

        $toggle = $active ? 'deactivate' : 'activate';
        $icon   = $active ? 'bi-slash-circle' : 'bi-check-circle';
        $label  = esc(lang('Accounting_cost_centers.' . $toggle), 'attr');
        $html .= '<button type="button" class="btn btn-link p-0 acc-action" data-acc-action="' . $toggle . '" data-acc-id="' . $id . '" title="' . $label . '" aria-label="' . $label . '"><span class="bi ' . $icon . '" aria-hidden="true"></span></button>';

        if (! (int) $center->has_children && ! (int) $center->used) {
            $delete = esc(lang('Common.delete'), 'attr');
            $html .= '<button type="button" class="btn btn-link p-0 acc-action text-danger" data-acc-action="delete" data-acc-id="' . $id . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
        }

        return $html;
    }
}
