<?php

namespace App\Controllers;

use App\Libraries\MasterData\ItemStatus;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockDocumentSettings;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockShelves;
use App\Libraries\Stock\UnitTemplates;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * What the screens of the stock documents share: the module they belong to, which opens while
 * accounting is off because the shelves move either way, the lists of locations the employee may use,
 * the paging and date filters, and the lookup of the stock items to put on a line.
 */
abstract class StockDocumentController extends AccountingController
{
    protected const PAGE_SIZE    = 25;
    protected const LOOKUP_LIMIT = 20;

    public function __construct()
    {
        parent::__construct(StockPermissions::MODULE, false);
    }

    protected function offset(): int
    {
        return max(0, (int) $this->request->getGet('offset'));
    }

    protected function postedText(string $name): string
    {
        return $this->textOf($this->request->getPost($name));
    }

    protected function queryText(string $name): string
    {
        return $this->textOf($this->request->getGet($name));
    }

    protected function textOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected function dateOf(string $name): string
    {
        $value = trim($this->queryText($name));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * The stock locations the employee may change: those they hold the items permission for, in the
     * branches they work in.
     *
     * @return array<int, string>
     */
    protected function ownLocations(): array
    {
        return model(Stock_location::class)->get_allowed_locations('items', true);
    }

    /**
     * Every stock location, to choose the one stock is asked from.
     *
     * @return array<int, string>
     */
    protected function everyLocation(): array
    {
        $locations = [];

        foreach (model(Stock_location::class)->get_all()->getResult() as $location) {
            $locations[(int) $location->location_id] = (string) $location->location_name;
        }

        return $locations;
    }

    protected function stockSettings(): StockDocumentSettings
    {
        return new StockDocumentSettings();
    }

    protected function forbidden(): ResponseInterface
    {
        return $this->response->setStatusCode(403);
    }

    protected function itemLookup(): ResponseInterface
    {
        $builder = Database::connect()->table('items')
            ->select('item_id, name, item_number')
            ->where(['deleted' => 0, 'stock_type' => HAS_STOCK, 'status' => ItemStatus::Active->value]);

        $term   = trim($this->queryText('term'));
        $itemId = (int) $this->request->getGet('item_id');

        if ($term !== '') {
            $builder->groupStart()->like('name', $term)->orLike('item_number', $term)->groupEnd();
        }

        if ($itemId > 0) {
            $builder->where('item_id', $itemId);
        }

        $rows       = $builder->orderBy('name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();
        $locationId = (int) $this->request->getGet('location_id');
        $shelves    = $locationId > 0 && array_key_exists($locationId, $this->ownLocations()) ? new StockShelves() : null;

        return $this->response->setJSON(array_map(function (object $row) use ($shelves, $locationId): array {
            $found = ['value' => (int) $row->item_id, 'label' => $row->name . ($row->item_number !== null && $row->item_number !== '' ? ' (' . $row->item_number . ')' : '')];

            if ($shelves !== null) {
                $found['on_hand'] = $shelves->onHand((int) $row->item_id, $locationId);
            }

            return $found + $this->stockProfileOf((int) $row->item_id);
        }, $rows));
    }

    /**
     * How an item may be entered on a line: its tracking type while tracking is on, and the units of its
     * template while units are on.
     *
     * @return array{tracking?: string, units?: list<array{value: string, label: string}>}
     */
    private function stockProfileOf(int $itemId): array
    {
        $options = new StockOptions();
        $profile = [];

        if (! $options->trackingEnabled() && ! $options->unitsEnabled()) {
            return $profile;
        }

        $item = (new ItemStockProfile())->get($itemId);

        if ($options->trackingEnabled()) {
            $profile['tracking'] = $item->tracking->value;
        }

        $template = $options->unitsEnabled() && $item->unitTemplateId !== null ? (new UnitTemplates())->find($item->unitTemplateId) : null;

        if ($template !== null) {
            $profile['units'] = [['value' => '', 'label' => $template->mainUnitName]];

            foreach ($template->units as $unit) {
                $profile['units'][] = ['value' => (string) $unit->unitId, 'label' => $unit->name . ' (x' . rtrim(rtrim($unit->factor, '0'), '.') . ')'];
            }
        }

        return $profile;
    }

    /**
     * The lines a form posts, with only what a line may hold. A row left blank is no line.
     *
     * @return list<array<string, mixed>>
     */
    protected function postedLines(): array
    {
        $lines = $this->request->getPost('lines');

        if (! is_array($lines)) {
            return [];
        }

        $list = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $row = [
                'item_id'   => trim($this->textOf($line['item_id'] ?? '')),
                'quantity'  => trim($this->textOf($line['quantity'] ?? '')),
                'unit_cost' => trim($this->textOf($line['unit_cost'] ?? '')),
            ];

            $unit = trim($this->textOf($line['unit_id'] ?? ''));

            if ($unit !== '') {
                $row += ['unit_id' => $unit, 'unit_quantity' => $row['quantity'], 'unit_price' => $row['unit_cost']];
            }

            $serials = trim($this->textOf($line['serials'] ?? ''));
            $batch   = trim($this->textOf($line['batch_number'] ?? ''));

            if ($serials !== '') {
                $row['tracking'] = ['serials' => $serials];
            } elseif ($batch !== '') {
                $row['tracking'] = ['batches' => [['number' => $batch, 'expiry' => trim($this->textOf($line['batch_expiry'] ?? '')), 'quantity' => '']]];
            }

            if ($row['item_id'] !== '' || $row['quantity'] !== '') {
                $list[] = $row;
            }
        }

        return $list;
    }
}
