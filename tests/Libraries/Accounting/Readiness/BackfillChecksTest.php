<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\Readiness\BackfillChecks;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class BackfillChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PreviewJournalFixtureTrait;
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

        $this->startBackfill(true, false);
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function outcome(): CheckResult
    {
        return (new BackfillChecks($this->accountingDb()))->run()[0];
    }

    public function testRun_GivesOneCheckWithItsReference(): void
    {
        $result = $this->outcome();

        $this->assertSame('backfill', $result->id);
        $this->assertSame('A6-BACKFILL', $result->ref);
    }

    public function testRun_PassesWhenThePostingIsOffNoBatchIsOpenAndEveryDocumentHasItsJournal(): void
    {
        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertFalse($result->data['enabled']);
        $this->assertSame([], $result->data['waiting']);
    }

    public function testRun_StatesWhatApprovingNeeds(): void
    {
        $result = $this->outcome();

        $this->assertStringContainsString('accounting_backfill_enabled', $result->findings[0]);
        $this->assertStringContainsString('opening balances', $result->findings[0]);
        $this->assertStringContainsString('start date', $result->findings[0]);
    }

    public function testRun_ReviewsDocumentsWithNoJournalAndCountsThemByFamily(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense();
        $this->legacyExpense('50', '0', '2026-03-13');

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['sales' => 1, 'expenses' => 2], $result->data['waiting']);
    }

    public function testRun_ListsWhatStandsInTheWayOfApproving(): void
    {
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['source_type' => 'manual']);
        $this->setConfigValue('accounting_backfill_enabled', '1');

        $result = $this->outcome();

        $this->assertSame(['opening_missing:HQ'], $result->data['blockers']);
        $this->assertSame(CheckStatus::Review, $result->status);
    }

    public function testRun_ListsTheOpenBatches(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed();
        $result = $this->outcome();

        $this->assertSame([['batch_id' => $batch->id, 'name' => $batch->name, 'status' => 'preview', 'ready' => 1, 'refused' => 0]], $result->data['open_batches']);
        $this->assertSame(CheckStatus::Review, $result->status);
    }

    public function testRun_FailsWhenAPreviewBelongsToNoOpenBatch(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->backfill->cancel($batch->id, $this->backfillManager);
        $this->accountingDb()->table('accounting_journals')->insert(['company_id' => 1, 'branch_id' => 1, 'series' => 'auto', 'journal_date' => '2026-03-10', 'status' => 'preview', 'source_type' => 'sale', 'source_id' => 5, 'currency_code' => 'SAR', 'backfill_batch_id' => $batch->id]);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['orphan_previews']);
    }

    private function legacyBalances(): CheckResult
    {
        return (new BackfillChecks($this->accountingDb()))->run()[1];
    }

    public function testRun_GivesTheLegacyBalancesCheckSecondWithItsReference(): void
    {
        $result = $this->legacyBalances();

        $this->assertSame('legacy_balances', $result->id);
        $this->assertSame('LEGACY-BALANCES-OUTSIDE-BOOKS', $result->ref);
    }

    public function testRun_PassesWhenNoCustomerOrSupplierCarriesABalanceInAnOldDocument(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $result = $this->legacyBalances();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['customers']['before']['parties']);
        $this->assertSame(0, $result->data['suppliers']['before']['parties']);
    }

    public function testRun_ReviewsACustomerWhoseCreditSaleIsDatedBeforeTheStartDateAndNamesTheRemedy(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.due'), '816.50')]);
        $this->legacyInvoice('2026-04-10', [$this->payment(lang('Sales.due'), '100.00'), $this->payment(lang('Sales.cash'), '50.00')]);

        $result   = $this->legacyBalances();
        $customer = $result->data['customers'];

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame([1, 2, '916.5000', '0.0000', '916.5000'], [$customer['before']['parties'], $customer['before']['documents'], $customer['before']['positive'], $customer['before']['negative'], $customer['before']['net']]);
        $this->assertSame(0, $customer['since']['parties']);
        $this->assertStringContainsString('import their opening balances', implode("\n", $result->findings));
        $this->assertStringContainsString('1 customers carry a net 916.5000 in 2 document(s)', implode("\n", $result->findings));
    }

    public function testRun_CountsWhatWasDatedSinceTheStartDateApartBecauseABatchPostsIt(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.due'), '816.50')]);

        $result = $this->legacyBalances();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['parties' => 0, 'documents' => 0, 'positive' => '0.0000', 'negative' => '0.0000', 'net' => '0.0000'], $result->data['customers']['before']);
        $this->assertSame(1, $result->data['customers']['since']['parties']);
        $this->assertSame('816.5000', $result->data['customers']['since']['net']);
        $this->assertStringContainsString('run the retroactive posting', implode("\n", $result->findings));
    }

    public function testRun_LeavesOutACustomerWhoseOpeningBalanceCoversTheDocument(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.due'), '816.50')]);

        $this->assertSame(1, $this->legacyBalances()->data['customers']['before']['parties']);

        (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '816.50', '2026-05-31', '', $this->backfillManager);

        $this->assertSame(0, $this->legacyBalances()->data['customers']['before']['parties']);

        $this->setConfigValue('accounting_customer_opening', '0');
    }

    public function testRun_StillCountsADocumentDatedAfterTheDayOfTheOpeningBalanceOfItsCustomer(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->setConfigValue('accounting_customer_opening', '1');
        (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '500.00', '2026-01-01', '', $this->backfillManager);
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.due'), '816.50')]);

        $this->assertSame(1, $this->legacyBalances()->data['customers']['since']['parties']);

        $this->setConfigValue('accounting_customer_opening', '0');
    }

    public function testRun_LeavesOutADocumentTheBooksHold(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->setConfigValue('accounting_backfill_enabled', '1');
        $this->legacyInvoice('2026-07-10', [$this->payment(lang('Sales.due'), '816.50')]);

        $this->assertSame(1, $this->legacyBalances()->data['customers']['since']['parties']);

        $this->approved($this->previewed(['from' => '2026-07-01', 'to' => '2026-07-31']));

        $this->assertSame(0, $this->legacyBalances()->data['customers']['since']['parties']);
    }

    public function testRun_ReviewsASupplierWhoseCreditPurchaseHasNoJournalAndHoldsTheSignWeOwe(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->whileOff(fn (): int => $this->datePurchase($this->purchaseOf('500', null, 'Due'), '2026-03-10'));

        $result   = $this->legacyBalances();
        $supplier = $result->data['suppliers']['before'];

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame([1, 1, '500.0000', '0.0000'], [$supplier['parties'], $supplier['documents'], $supplier['positive'], $supplier['negative']]);
        $this->assertStringContainsString('1 suppliers carry a net 500.0000', implode("\n", $result->findings));
    }

    public function testRun_PassesWithTheCountsWhileAccountingIsOffBecauseNothingIsExpectedInTheBooksYet(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.due'), '816.50')]);
        $this->setConfigValue('accounting_enabled', '0');

        try {
            $result = $this->legacyBalances();
        } finally {
            $this->setConfigValue('accounting_enabled', '1');
        }

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(1, $result->data['customers']['before']['parties']);
        $this->assertFalse($result->data['enabled']);
    }

    public function testRun_SaysWhichFiscalYearsAreMissingForDocumentsAfterTheLastYear(): void
    {
        $this->legacyExpense('100', '0', '2037-03-10');
        $this->legacyExpense('100', '0', '2036-02-01');

        $result  = $this->outcome();
        $outside = $result->data['outside_fiscal_year'];

        $this->assertSame([2, '2036-01-01', '2037-03-10', ['2036', '2037']], [$outside['documents'], $outside['from'], $outside['to'], $outside['years']]);
        $this->assertStringContainsString('2 document(s) with no journal fall outside every fiscal year (dated 2036-01-01 to 2037-03-10): create the fiscal years 2036 to 2037', implode("\n", $result->findings));
        $this->assertStringContainsString('php spark accounting:enable', implode("\n", $result->findings));
    }

    public function testRun_SaysNothingOfFiscalYearsWhenEveryDocumentFallsInOne(): void
    {
        $this->legacyExpense('100', '0', '2026-03-10');

        $this->assertNull($this->outcome()->data['outside_fiscal_year']);
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->previewed();

        $before = $this->databaseFingerprint();

        $this->outcome();
        $this->legacyBalances();

        $this->assertDatabaseUnchanged($before);
    }
}
