<?php

namespace Tests\Support;

use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\StockLedger;

/**
 * Runs the scenarios of purchases kept in Golden/purchases-without-invoice-discount.json through the
 * real purchase model and reads back everything they put in the books, in a form that does not depend
 * on the ids the database gave. A class that uses it also uses PurchaseAccountingFixtureTrait and
 * what that one needs.
 */
trait PurchaseGoldenTrait
{
    private const GOLDEN_FILE = __DIR__ . '/Golden/purchases-without-invoice-discount.json';

    /**
     * @return list<array<string, mixed>>
     */
    protected static function goldenScenarios(): array
    {
        return json_decode((string) file_get_contents(self::GOLDEN_FILE), true, 512, JSON_THROW_ON_ERROR)['scenarios'];
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return list<array<string, mixed>> What each step of the scenario left in the books.
     */
    protected function runGoldenScenario(array $scenario): array
    {
        $items = [];

        foreach ($scenario['items'] as $spec) {
            $items[] = $this->item($spec['cost'], $spec['stock'] ?? true);
        }

        $locations = [self::LOCATION_ID];

        for ($index = 1; $index < ($scenario['locations'] ?? 1); $index++) {
            $locations[] = $this->anotherLocation('Golden ' . $index);
        }

        $roles = [
            'inventory' => $this->stockAccount(),
            'tax_input' => $this->accountId('tax_input'),
            'supplier'  => $this->supplierAccount(),
            'cash'      => $this->accountId('cash'),
            'cogs'      => $this->accountId('cogs'),
            'purchases' => $this->accountId('purchases'),
        ];

        foreach ($locations as $index => $locationId) {
            if ($index > 0) {
                $roles['inventory@' . $index] = $this->stockAccountOf($locationId);
            }
        }

        $roleOf = array_flip($roles);
        $saved  = [];
        $result = [];

        foreach ($scenario['steps'] as $number => $step) {
            $lines = [];

            foreach ($step['lines'] as $index => $line) {
                $lines[] = $this->purchaseLine($items[$line['item']], $index, $line['q'], $line['price'], $line['tax'] ?? '0', $line['discount'] ?? '0', $line['type'] ?? PERCENT, $locations[$line['loc'] ?? 0], $line['pack'] ?? '1');
            }

            $id = $this->buy(
                $lines,
                ($step['supplier'] ?? true) ? -1 : null,
                ($step['payment'] ?? null) === 'cash' ? lang('Sales.cash') : null,
                isset($step['origin']) ? $saved[$step['origin']] : null,
            );

            if ($id <= 0) {
                $failure = $this->purchases->accountingFailure();

                $result[] = ['saved' => false, 'error' => $failure instanceof PostingException ? $failure->error->value : 'other'];

                continue;
            }

            $saved[$number] = $id;
            $result[]       = ['saved' => true] + $this->goldenReading($id, $items, $locations, $roleOf);
        }

        return $result;
    }

    /**
     * @param list<int>          $items
     * @param list<int>          $locations
     * @param array<int, string> $roleOf
     *
     * @return array<string, mixed>
     */
    private function goldenReading(int $receivingId, array $items, array $locations, array $roleOf): array
    {
        $db       = $this->accountingDb();
        $journals = [];

        foreach ((new ReceivingPosting())->journalsOf($receivingId) as $journal) {
            $journals[] = [
                'source' => $journal->source_type,
                'status' => $journal->status,
                'lines'  => array_map(
                    static fn (object $line): string => ($roleOf[(int) $line->account_id] ?? 'account') . ':' . $line->debit . ':' . $line->credit,
                    $db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
                ),
            ];
        }

        $movements = array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->kind . ':i' . array_search((int) $row->item_id, $items, true) . '@' . array_search((int) $row->location_id, $locations, true) . ':' . $row->quantity . ':' . $row->value,
            $db->table('accounting_stock_entries')->select('source_type, kind, item_id, location_id, quantity, value')->where('source_id', $receivingId)->orderBy('entry_id', 'ASC')->get()->getResult(),
        );

        $costs = [];
        $stock = [];

        foreach ($items as $index => $itemId) {
            $costs[] = 'i' . $index . ':' . $this->exactCostOf($itemId);

            foreach ($locations as $place => $locationId) {
                $position = (new StockLedger())->position($itemId, $locationId);

                $stock[] = 'i' . $index . '@' . $place . ':' . bcadd($position['quantity'], '0', 3) . ':' . bcadd($position['value'], '0', 4);
            }
        }

        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('receivings_items_temp'));
        $this->purchases->create_temp_table(['receiving_id' => $receivingId]);

        $report = array_map(
            static fn (object $row): string => $row->line . ':' . bcadd((string) $row->subtotal, '0', 4) . ':' . bcadd((string) $row->total, '0', 4) . ':' . bcadd((string) $row->profit, '0', 4),
            $db->table('receivings_items_temp')->select('line, subtotal, total, profit')->orderBy('line', 'ASC')->get()->getResult(),
        );

        return ['journals' => $journals, 'movements' => $movements, 'costs' => $costs, 'stock' => $stock, 'report' => $report];
    }
}
