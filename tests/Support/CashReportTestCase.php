<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The ground of the tests of the reports of expenses and receipt vouchers: a migrated database,
 * accounting on, an employee who works in every branch, and what is needed to save expenses through
 * the real model and to ask for a report and read what it answers.
 *
 * @internal
 */
abstract class CashReportTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use FamilyReportFixtureTrait;

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

        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopExpenses();
        $this->resetBranches();

        $db = $this->accountingDb();
        $db->table('revenues')->truncate();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    protected function now(): string
    {
        return $this->today() . ' 10:00:00';
    }

    /**
     * @return array<string, mixed>
     */
    protected function thisDay(): array
    {
        return ['from' => $this->today(), 'to' => $this->today()];
    }
}
