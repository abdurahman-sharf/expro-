<?php

namespace App\Controllers;

use App\Libraries\Stock\StockDocumentSettings;
use App\Libraries\Stock\StockPermissions;
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

    protected function dateOf(string $name): string
    {
        $value = trim((string) $this->request->getGet($name));

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
            ->where(['deleted' => 0, 'stock_type' => HAS_STOCK]);

        $term = trim((string) $this->request->getGet('term'));

        if ($term !== '') {
            $builder->groupStart()->like('name', $term)->orLike('item_number', $term)->groupEnd();
        }

        $rows = $builder->orderBy('name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->item_id, 'label' => $row->name . ($row->item_number !== null && $row->item_number !== '' ? ' (' . $row->item_number . ')' : '')], $rows));
    }

    /**
     * The lines a form posts, with only what a line may hold. A row left blank is no line.
     *
     * @return list<array{item_id: string, quantity: string, unit_cost: string}>
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
                'item_id'   => trim((string) ($line['item_id'] ?? '')),
                'quantity'  => trim((string) ($line['quantity'] ?? '')),
                'unit_cost' => trim((string) ($line['unit_cost'] ?? '')),
            ];

            if ($row['item_id'] !== '' || $row['quantity'] !== '') {
                $list[] = $row;
            }
        }

        return $list;
    }
}
