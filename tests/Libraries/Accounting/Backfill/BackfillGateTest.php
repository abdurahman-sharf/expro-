<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\Backfill\BackfillGate;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class BackfillGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBackfill();
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function gate(): BackfillGate
    {
        return new BackfillGate($this->accountingDb());
    }

    public function testRefusalOf_PutsOutADayBeforeTheBooksBeginAndTheStartDateItselfIsIn(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');

        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->gate()->refusalOf('2026-05-31', 1));
        $this->assertNull($this->gate()->refusalOf('2026-06-01', 1));
        $this->assertNull($this->gate()->refusalOf('2026-06-02', 1));
    }

    public function testRefusalOf_PutsOutADayBeforeTheOpeningBalancesOfThatBranchOnly(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['journal_date' => '2026-03-20']);
        $this->postOpening($other);

        $this->assertSame(BackfillRefusal::BeforeOpening, $this->gate()->refusalOf('2026-03-19', 1));
        $this->assertNull($this->gate()->refusalOf('2026-03-20', 1));
        $this->assertNull($this->gate()->refusalOf('2026-03-19', $other));
    }

    public function testOpeningOf_GivesTheDayOfThePostedOpeningBalancesAndNullForABranchWithNone(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->assertSame('2020-01-01', $this->gate()->openingOf(1));
        $this->assertNull($this->gate()->openingOf($other));
    }

    public function testOpeningOf_IgnoresOpeningBalancesThatWereReversed(): void
    {
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['status' => 'reversed']);

        $this->assertNull($this->gate()->openingOf(1));
    }
}
