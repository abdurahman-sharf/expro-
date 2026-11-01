<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The ground of the tests of the reports of the families: a migrated database, accounting on, an
 * employee, a supplier, and what is needed to put purchases through the real model and to ask for a
 * report and read what it answers.
 *
 * @internal
 */
abstract class FamilyReportTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use FamilyReportFixtureTrait;
    use ShelfFixtureTrait;
    use StockBooksFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * Two suppliers: the first has invoices of 230 and 115 and a return of 50 against the second of
     * them, the second supplier has one of 100.
     *
     * @return array{first: int, second: int, big: int, small: int, note: int, other: int, item: int}
     */
    protected function market(): array
    {
        $item   = $this->item('0.00');
        $other  = $this->anotherSupplier();
        $big    = $this->buy([$this->purchaseLine($item, 0, '2', '100.00', '15')]);
        $small  = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '15')]);
        $note   = $this->debitNoteOf($small, '50.00');
        $second = $this->buy([$this->purchaseLine($item, 0, '1', '100.00')], $other);

        return ['first' => $this->supplierId, 'second' => $other, 'big' => $big, 'small' => $small, 'note' => $note, 'other' => $second, 'item' => $item];
    }

    protected function named(string $name, string $category = '', string $cost = '0.00'): int
    {
        $item = $this->item($cost);

        $this->accountingDb()->table('items')->where('item_id', $item)->update(['name' => $name, 'category' => $category, 'item_number' => 'N-' . $item]);

        return $item;
    }

    protected function lastBookedSource(): int
    {
        return $this->bookedSource;
    }

    protected function ago(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /**
     * @return array<string, string>
     */
    protected function thisDay(): array
    {
        return ['from' => $this->today(), 'to' => $this->today()];
    }
}
