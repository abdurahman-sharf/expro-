<?php

namespace Tests\Support;

use App\Libraries\Accounting\StockLedger;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Stock\StockDocumentSettings;
use App\Libraries\Stock\StockPermissions;

/**
 * What a test needs to put the stock documents through the real services with accounting on: an
 * employee who holds the permissions of the documents and the items permission of the stock locations,
 * stock locations with that permission, a way to put units on a shelf at a known cost, and a way to
 * clean up what the documents leave behind. A class that uses it also uses AccountingFixtureTrait,
 * EmployeeFixtureTrait, ItemFixtureTrait, SaleAccountingFixtureTrait and PurchaseAccountingFixtureTrait.
 */
trait StockDocumentFixtureTrait
{
    protected int $keeperId;

    /** @var list<int> */
    private array $grantedLocations = [];

    protected function startStockDocuments(): void
    {
        $this->startBuying();

        $this->keeperId   = $this->keeper();
        $this->employeeId = $this->keeperId;
    }

    protected function stopStockDocuments(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['stock_request_lines', 'stock_requests', 'stocktake_lines', 'stocktakes', 'stock_permit_lines', 'stock_permits'] as $table) {
            $db->table($table)->truncate();
        }

        $db->table('document_numbers')->whereIn('source_table', ['stock_permits', 'stocktakes', 'stock_requests'])->delete();
        $db->table('document_sequences')->whereIn('doc_type', array_map(static fn (DocumentType $type): string => $type->value, [DocumentType::StockPermitAdd, DocumentType::StockPermitIssue, DocumentType::StockPermitTransfer, DocumentType::Stocktake, DocumentType::StockRequest]))->delete();
        $db->table('inventory')->like('trans_comment', 'STKP ', 'after')->delete();
        $db->table('inventory')->like('trans_comment', 'STKT ', 'after')->delete();

        if ($this->grantedLocations !== []) {
            $db->table('grants')->whereIn('permission_id', array_map(static fn (int $locationId): string => 'items_test_location_' . $locationId, $this->grantedLocations))->delete();
            $db->table('permissions')->whereIn('location_id', $this->grantedLocations)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('app_config')->whereIn('key', [StockDocumentSettings::TRANSFER_PERMIT, StockDocumentSettings::REQUESTS])->delete();

        $this->grantedLocations = [];
    }

    /**
     * An employee who works in the branches given (every branch without a list) and holds every
     * permission of the stock documents and the items permission of the stock location of the tests.
     *
     * @param list<int>|null $branchIds
     */
    protected function keeper(?array $branchIds = null, array $permissions = [...StockPermissions::ALL, StockPermissions::MODULE, 'items_stock']): int
    {
        return $this->employeeWithGrants($permissions, $branchIds);
    }

    /**
     * A stock location of the default branch with its account and the items permission that goes with
     * it, granted to the employee given.
     */
    protected function openLocation(string $name = 'Second warehouse', ?int $employeeId = null, int $branchId = 1): int
    {
        $locationId = $this->anotherLocation($name);

        $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->update(['branch_id' => $branchId]);
        $this->allowLocation($locationId, $employeeId ?? $this->keeperId);

        return $locationId;
    }

    protected function allowLocation(int $locationId, int $employeeId): void
    {
        $db         = $this->accountingDb();
        $permission = 'items_test_location_' . $locationId;

        $db->table('permissions')->ignore(true)->insert(['permission_id' => $permission, 'module_id' => 'items', 'location_id' => $locationId]);
        $db->table('grants')->ignore(true)->insert(['permission_id' => $permission, 'person_id' => $employeeId, 'menu_group' => 'home']);

        $this->grantedLocations[] = $locationId;
    }

    /**
     * An item with units on the shelf of a location, bought through the real purchase model so that the
     * books hold them at the cost: the quantity and what they cost a unit.
     */
    protected function stocked(string $units, string $cost, int $locationId = self::LOCATION_ID): int
    {
        $itemId = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, $units, $cost, locationId: $locationId)]));

        return $itemId;
    }

    /**
     * Two processes make the same kind of change at the same moment, each over a connection of its own,
     * and say how it ended: done, or the error of the books that refused it. A class that uses it also
     * uses ConcurrentDbRaceTrait.
     *
     * @param list<string> $firstArguments
     * @param list<string> $secondArguments
     *
     * @return list<string> The two answers, sorted.
     */
    protected function raceStockWorkers(string $method, array $firstArguments, array $secondArguments): array
    {
        $files = [(string) tempnam(sys_get_temp_dir(), 'race_out_'), (string) tempnam(sys_get_temp_dir(), 'race_out_')];

        try {
            $this->raceTwoProcesses($method, [...$firstArguments, $files[0]], [...$secondArguments, $files[1]]);

            $answers = [trim((string) file_get_contents($files[0])), trim((string) file_get_contents($files[1]))];

            sort($answers);

            return $answers;
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    protected function switchOnTransferPermit(): void
    {
        $this->setConfigValue(StockDocumentSettings::TRANSFER_PERMIT, '1');
    }

    protected function switchOnRequests(): void
    {
        $this->setConfigValue(StockDocumentSettings::REQUESTS, '1');
    }

    protected function numberWith(DocumentType $type, string $prefix = '', int $padding = 6): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding]);
    }

    /**
     * @return list<object>
     */
    protected function journalsOfSource(string $source, int $sourceId): array
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', $source)->where('source_id', $sourceId)->orderBy('journal_id', 'ASC')->get()->getResult();
    }

    /**
     * The lines of a journal as account and the amounts, debit first.
     *
     * @return list<string>
     */
    protected function linesOfJournal(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    /**
     * @return list<object>
     */
    protected function movementsOfSource(string $source, int $sourceId): array
    {
        return $this->accountingDb()->table('accounting_stock_entries')->where('source_type', $source)->where('source_id', $sourceId)->orderBy('entry_id', 'ASC')->get()->getResult();
    }

    /**
     * Every location says in its account what its movements say, and every journal agrees with its
     * movements, and the shelves of the items given hold what the movements of the books hold.
     */
    protected function assertBooksTie(): void
    {
        $ledger = new StockLedger();

        $this->assertSame([], $ledger->mismatchedJournals(), 'A journal and its stock movements disagree');

        foreach ($ledger->reconcile() as $location) {
            $this->assertSame(bcadd($location['movements_value'], '0', 2), bcadd($location['account_balance'], '0', 2), 'Location ' . $location['location_id'] . ': the account and the movements differ');
        }
    }
}
