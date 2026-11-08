<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockUnitPermissions;
use App\Libraries\Stock\TrackingType;
use App\Libraries\Stock\UnitBarcodes;
use App\Libraries\Stock\UnitTemplates;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * The screens of unit templates, of the units and tracking of an item, and of the settings of units,
 * bundles, tracking and negative stock. The module opens while accounting is off, because the units and
 * the tracking of stock work either way.
 */
class StockUnits extends AccountingController
{
    public function __construct()
    {
        parent::__construct(StockUnitPermissions::MODULE, false);
    }

    public function getIndex(): string
    {
        $options = new StockOptions();

        return view('stock_units/manage', [
            'templates'   => (new UnitTemplates())->all(),
            'used'        => $this->usedTemplates(),
            'settings'    => $options->all(),
            'canManage'   => $this->can(StockUnitPermissions::UNITS_MANAGE),
            'canSettings' => $this->can(AccountingGuard::SETTINGS_MANAGE),
            'item'        => $this->itemOf($this->queryText('item')),
            'itemSearch'  => $this->queryText('item'),
        ]);
    }

    public function getTemplateForm(string $templateId = '-1'): ResponseInterface|string
    {
        if (! $this->can(StockUnitPermissions::UNITS_MANAGE)) {
            return $this->forbidden();
        }

        $templates = new UnitTemplates();
        $template  = ctype_digit($templateId) ? $templates->find((int) $templateId) : null;

        return view('stock_units/template_form', ['template' => $template, 'used' => $template !== null && $templates->isUsed($template->templateId)]);
    }

    public function postSaveTemplate(string $templateId = '-1'): ResponseInterface
    {
        return $this->attempt(function () use ($templateId): array {
            $templates = new UnitTemplates();
            $input     = $this->templateInput();

            if (ctype_digit($templateId) && $this->request->getPost('as_new_version') === '1') {
                $newId = $templates->newVersion((int) $templateId, $input, $this->employeeId());

                return ['success' => true, 'message' => lang('Stock_units.template_versioned'), 'id' => $newId];
            }

            if (ctype_digit($templateId)) {
                $templates->update((int) $templateId, $input, $this->employeeId());

                return ['success' => true, 'message' => lang('Stock_units.template_saved'), 'id' => (int) $templateId];
            }

            return ['success' => true, 'message' => lang('Stock_units.template_saved'), 'id' => $templates->create($input, $this->employeeId())];
        });
    }

    public function postSetActive(int $templateId): ResponseInterface
    {
        return $this->attempt(function () use ($templateId): array {
            (new UnitTemplates())->setActive($templateId, $this->request->getPost('active') === '1', $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_units.template_saved'), 'id' => $templateId];
        });
    }

    public function postSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $input = [];

            foreach (['units', 'bundles', 'tracking', 'expired_refused'] as $flag) {
                if ($this->request->getPost($flag) !== null) {
                    $input[$flag] = $this->request->getPost($flag) === '1';
                }
            }

            if ($this->request->getPost('negative_stock') !== null) {
                $input['negative_stock'] = $this->textOf($this->request->getPost('negative_stock'));
            }

            (new StockOptions())->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_units.settings_saved'), 'id' => 1];
        });
    }

    public function postSaveItem(int $itemId): ResponseInterface
    {
        return $this->attempt(function () use ($itemId): array {
            $profiles = new ItemStockProfile();
            $template = $this->textOf($this->request->getPost('unit_template_id'));
            $tracking = TrackingType::tryFrom($this->textOf($this->request->getPost('tracking_type'))) ?? TrackingType::None;

            $profiles->setTemplate($itemId, $template === '' ? null : (int) $template, $this->employeeId());
            $profiles->setTracking($itemId, $tracking, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_units.profile_saved'), 'id' => $itemId];
        });
    }

    public function postAddBarcode(int $itemId): ResponseInterface
    {
        return $this->attempt(function () use ($itemId): array {
            $unit = $this->textOf($this->request->getPost('unit_id'));
            $id   = (new UnitBarcodes())->assign($itemId, $unit === '' ? null : (int) $unit, $this->textOf($this->request->getPost('barcode')), $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_units.barcode_saved'), 'id' => $id];
        });
    }

    public function postRemoveBarcode(int $barcodeId): ResponseInterface
    {
        return $this->attempt(function () use ($barcodeId): array {
            (new UnitBarcodes())->remove($barcodeId, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_units.barcode_removed'), 'id' => $barcodeId];
        });
    }

    /**
     * @return array{name: string, main_unit_name: string, main_small_name: string, active: bool, units: list<array{name: string, factor: string, small_name: string}>}
     */
    private function templateInput(): array
    {
        $units = [];
        $names = (array) $this->request->getPost('unit_name');
        $facts = (array) $this->request->getPost('unit_factor');
        $small = (array) $this->request->getPost('unit_small_name');

        foreach ($names as $index => $name) {
            if (trim($this->textOf($name)) === '' && trim($this->textOf($facts[$index] ?? '')) === '') {
                continue;
            }

            $units[] = ['name' => $this->textOf($name), 'factor' => $this->textOf($facts[$index] ?? ''), 'small_name' => $this->textOf($small[$index] ?? '')];
        }

        return [
            'name'            => $this->textOf($this->request->getPost('name')),
            'main_unit_name'  => $this->textOf($this->request->getPost('main_unit_name')),
            'main_small_name' => $this->textOf($this->request->getPost('main_small_name')),
            'active'          => $this->request->getPost('active') !== '0',
            'units'           => $units,
        ];
    }

    /**
     * @return array<int, bool>
     */
    private function usedTemplates(): array
    {
        $templates = new UnitTemplates();
        $used      = [];

        foreach ($templates->all() as $template) {
            $used[$template->templateId] = $templates->isUsed($template->templateId);
        }

        return $used;
    }

    /**
     * @return array{id: int, name: string, template: ?int, tracking: string, barcodes: list<object>, units: array<int, string>}|null
     */
    private function itemOf(string $search): ?array
    {
        $search = trim($search);

        if ($search === '') {
            return null;
        }

        $db   = Database::connect();
        $item = $db->table('items')->select('item_id, name')->where('deleted', 0)->groupStart()->where('item_id', ctype_digit($search) ? (int) $search : 0)->orWhere('item_number', $search)->groupEnd()->get()->getRow();

        if ($item === null) {
            return null;
        }

        $profile  = (new ItemStockProfile($db))->get((int) $item->item_id);
        $template = $profile->unitTemplateId === null ? null : (new UnitTemplates($db))->find($profile->unitTemplateId);
        $units    = [];

        foreach ($template?->units ?? [] as $unit) {
            $units[$unit->unitId] = $unit->name;
        }

        return ['id' => (int) $item->item_id, 'name' => (string) $item->name, 'template' => $profile->unitTemplateId, 'tracking' => $profile->tracking->value, 'barcodes' => (new UnitBarcodes($db))->ofItem((int) $item->item_id), 'units' => $units];
    }

    private function queryText(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_scalar($value) ? (string) $value : '';
    }

    private function textOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function forbidden(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setBody('');
    }
}
