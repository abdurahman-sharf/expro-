<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\TieChecks;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What must add up in the books, read by the readiness report: customers and suppliers against
 * their documents, treasuries and stock locations against their accounts, and the branches against
 * each other. A manual journal on one of these accounts is the difference each fixture writes.
 */
class TieChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private TieChecks $checks;
    private TreasuryService $treasuries;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->checks     = new TieChecks($this->accountingDb());
        $this->treasuries = new TreasuryService();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach (['accounting_interbranch_legs'] as $table) {
            if (! $db->tableExists('ospos_' . $table, false) && $db->tableExists('ospos_' . $table . '_hold', false)) {
                $db->query("RENAME TABLE ospos_{$table}_hold TO ospos_{$table}");
            }
        }

        $this->stopBuying();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    private function byHand(int $accountId, string $amount, bool $debit = true, string $sourceType = 'manual'): void
    {
        $this->accountingDb()->table('accounting_journals')->insert([
            'company_id'    => 1,
            'branch_id'     => 1,
            'series'        => 'manual',
            'journal_date'  => '2026-10-21',
            'status'        => 'posted',
            'source_type'   => $sourceType,
            'entry_type'    => 'general',
            'revision'      => 1,
            'origin'        => 'user',
            'currency_code' => 'SAR',
            'total_debit'   => $amount,
            'total_credit'  => $amount,
            'posted_at'     => date('Y-m-d H:i:s'),
        ]);

        $journalId = (int) $this->accountingDb()->insertID();

        $this->accountingDb()->table('accounting_journal_lines')->insertBatch([
            ['journal_id' => $journalId, 'line_no' => 1, 'account_id' => $accountId, 'debit' => $debit ? $amount : '0', 'credit' => $debit ? '0' : $amount],
            ['journal_id' => $journalId, 'line_no' => 2, 'account_id' => $this->accountId('opening_balance'), 'debit' => $debit ? '0' : $amount, 'credit' => $debit ? $amount : '0'],
        ]);
    }

    public function testRun_GivesTheSixChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['customer_ties', 'supplier_ties', 'treasury_ties', 'stock_ties', 'interbranch_unattributed', 'interbranch_ties'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['TIE-CUSTOMERS', 'TIE-SUPPLIERS', 'TIE-TREASURIES', 'TIE-STOCK', 'WP7-PRE-DEPLOY-QUERY', 'TIE-BRANCHES'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testCustomerTies_PassesForACustomerWhoseAccountMatchesTheirInvoicesAndCountsNoSupplier(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $this->addPayment($invoiceId, '30.00');
        $this->purchaseOf('500.00');

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(1, $result->data['checked']);
        $this->assertSame(0, $result->data['differences']);
    }

    public function testCustomerTies_PassesForACustomerWithAnOpeningBalanceThatCreditWasSpentOn(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        try {
            (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '500.00', date('Y-m-d', strtotime('-40 days')), '', null);
        } finally {
            $this->setConfigValue('accounting_customer_opening', '0');
        }

        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '300.00')]);

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['differences']);
    }

    public function testCustomerTies_IsSkippedWhileTheTableOfOpeningBalancesIsMissing(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('RENAME TABLE ospos_customer_openings TO ospos_customer_openings_hold');

        try {
            $result = $this->check('customer_ties');
        } finally {
            $db->query('RENAME TABLE ospos_customer_openings_hold TO ospos_customer_openings');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertContains('customer_openings', $result->data['missing']);
    }

    public function testCustomerTies_PassesWithNoCustomerMovement(): void
    {
        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['checked']);
    }

    public function testCustomerTies_FailsAndListsACustomerWhoseAccountHoldsWhatNoInvoiceExplains(): void
    {
        $this->invoiceOf('100.00');
        $this->byHand($this->customerAccount(), '50.00');

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString("customer #{$this->customerId}", $result->findings[0]);
        $this->assertStringContainsString('the account holds 150.0000, invoices less credit say 100.0000, unexplained 50.0000', $result->findings[0]);
        $this->assertSame(1, $result->data['differences']);
    }

    public function testCustomerTies_OnlyListsTheCustomerWhoDoesNotTie(): void
    {
        $other = $this->anotherCustomer();
        $this->invoiceOf('100.00');
        $this->invoiceOf('70.00', $other);
        $this->byHand($this->customerAccount($other), '5.00', false);

        $result = $this->check('customer_ties');

        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString("customer #{$other}", $result->findings[0]);
        $this->assertSame(2, $result->data['checked']);
    }

    public function testCustomerTies_PassesForACustomerWithSeveralOpenInvoicesInOneAgingBucket(): void
    {
        $this->invoiceOf('100.00');
        $this->invoiceOf('70.00');

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(1, $result->data['checked']);
    }

    public function testCustomerTies_PassesForAnInvoiceDatedAfterTheDayOfTheReport(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $this->invoiceOf('70.00');
        $this->accountingDb()->table('sales')->where('sale_id', $invoiceId)->update(['sale_time' => date('Y-m-d H:i:s', strtotime('+45 days'))]);

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status, 'An invoice dated ahead is open and counts in the aging: the check compares totals.');
        $this->assertSame(1, $result->data['checked']);
    }

    public function testSupplierTies_PassesForAPurchaseDatedAfterTheDayOfTheReport(): void
    {
        $receivingId = $this->purchaseOf('500.00');
        $this->purchaseOf('300.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['receiving_time' => date('Y-m-d H:i:s', strtotime('+45 days'))]);

        $result = $this->check('supplier_ties');

        $this->assertSame(CheckStatus::Pass, $result->status, 'A purchase dated ahead is open and counts in the aging: the check compares totals.');
        $this->assertSame(1, $result->data['checked']);
    }

    public function testCustomerTies_KeepsTheAgingTotalOfEveryCustomerInTheData(): void
    {
        $firstId  = $this->invoiceOf('100.00');
        $secondId = $this->invoiceOf('70.00');
        $this->accountingDb()->table('sales')->where('sale_id', $secondId)->update(['sale_time' => date('Y-m-d H:i:s', strtotime('+45 days'))]);
        $other    = $this->insertCustomer();
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $other, 'Second customer');
        $this->invoiceOf('25.50', $other);

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([$this->customerId, $other], array_keys($result->data['aging_totals']));
        $this->assertSame(0, bccomp('170.00', $result->data['aging_totals'][$this->customerId], 4), 'The invoice dated ahead is in the total of its customer.');
        $this->assertSame(0, bccomp('25.50', $result->data['aging_totals'][$other], 4));
        $this->assertSame(0, bccomp('195.50', $result->data['aging_total'], 4));
        $this->assertSame(2, $result->data['checked']);
        $this->assertGreaterThan(0, $firstId);
    }

    public function testCustomerTies_KeepsTheAgingTotalsInTheDataOfAFailingCheckToo(): void
    {
        $this->invoiceOf('100.00');
        $this->byHand($this->customerAccount(), '40.00');

        $result = $this->check('customer_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(0, bccomp('100.00', $result->data['aging_totals'][$this->customerId], 4));
    }

    public function testSupplierTies_KeepsTheAgingTotalOfEverySupplierInTheData(): void
    {
        $firstId = $this->purchaseOf('500.00');
        $this->purchaseOf('300.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $firstId)->update(['receiving_time' => date('Y-m-d H:i:s', strtotime('+45 days'))]);
        $other = $this->anotherSupplier();
        $this->purchaseOf('40.00', $other);

        $result = $this->check('supplier_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([$this->supplierId, $other], array_keys($result->data['aging_totals']));
        $this->assertSame(0, bccomp('800.00', $result->data['aging_totals'][$this->supplierId], 4), 'The purchase dated ahead is in the total of its supplier.');
        $this->assertSame(0, bccomp('40.00', $result->data['aging_totals'][$other], 4));
        $this->assertSame(0, bccomp('840.00', $result->data['aging_total'], 4));
    }

    public function testSupplierTies_ListsNoAgingTotalWhenNoSupplierHasMovements(): void
    {
        $result = $this->check('supplier_ties');

        $this->assertSame([], $result->data['aging_totals']);
        $this->assertSame(0, bccomp('0', $result->data['aging_total'], 4));
    }

    public function testSupplierTies_PassesForASupplierWhoseAccountMatchesTheirPurchases(): void
    {
        $this->purchaseOf('500.00');

        $result = $this->check('supplier_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(1, $result->data['checked']);
    }

    public function testSupplierTies_FailsAndListsASupplierWhoseAccountHoldsWhatNoPurchaseExplains(): void
    {
        $this->purchaseOf('500.00');
        $this->byHand($this->supplierAccount(), '40.00', false);

        $result = $this->check('supplier_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString("supplier #{$this->supplierId}", $result->findings[0]);
        $this->assertStringContainsString('the account holds 540.0000, purchases less credit say 500.0000, unexplained 40.0000', $result->findings[0]);
    }

    public function testSupplierTies_PassesForASupplierWithSeveralOpenPurchasesInOneAgingBucket(): void
    {
        $this->purchaseOf('500.00');
        $this->purchaseOf('300.00');

        $result = $this->check('supplier_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(1, $result->data['checked']);
    }

    public function testTreasuryTies_PassesForTreasuriesWhoseBalanceIsWhatDocumentsPutOnThem(): void
    {
        $this->treasuries->ensureMain();
        $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $this->saleWithCash();

        $result = $this->check('treasury_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(2, $result->data['checked']);
    }

    private function saleWithCash(): void
    {
        $item = $this->item('10.00');
        $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
    }

    public function testTreasuryTies_ReviewsAJournalWrittenByHandOnATreasuryAccountAndDoesNotFail(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $this->byHand((int) $this->treasuries->accountOf($drawer), '25.00');

        $result = $this->check('treasury_ties');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->status->blocks());
        $this->assertSame(["treasury {$drawer} (Drawer): its account holds 25.0000 and 25.0000 of it was put there by hand"], $result->findings);
        $this->assertSame(0, $result->data['differences']);
        $this->assertSame(1, $result->data['by_hand']);
        $this->assertSame('25.0000', $result->data['by_hand_total']);
    }

    public function testTreasuryTies_PassesWhenTheJournalsWrittenByHandNetToNothing(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $this->byHand((int) $this->treasuries->accountOf($drawer), '25.00');
        $this->byHand((int) $this->treasuries->accountOf($drawer), '25.00', false);

        $this->assertSame(CheckStatus::Pass, $this->check('treasury_ties')->status);
    }

    public function testTreasuryTies_FailsForATreasuryThatHasNoAccountEvenWhenAnotherHoldsAJournalWrittenByHand(): void
    {
        $drawer    = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $till      = $this->treasuries->create(['name' => 'Till', 'kind' => 'cash']);
        $accountId = (int) $this->treasuries->accountOf($drawer);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->update(['entity_type' => null, 'entity_id' => null]);
        $this->byHand((int) $this->treasuries->accountOf($till), '25.00');

        $result = $this->check('treasury_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertContains("treasury {$drawer} (Drawer) has no account", $result->findings);
        $this->assertContains("treasury {$till} (Till): its account holds 25.0000 and 25.0000 of it was put there by hand", $result->findings);
        $this->assertSame(1, $result->data['differences']);
        $this->assertSame(1, $result->data['by_hand']);
    }

    public function testTreasuryTies_FailsForATreasuryThatHasNoAccount(): void
    {
        $drawer    = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $accountId = (int) $this->treasuries->accountOf($drawer);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->update(['entity_type' => null, 'entity_id' => null]);

        $result = $this->check('treasury_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["treasury {$drawer} (Drawer) has no account"], $result->findings);
    }

    public function testTreasuryTies_CountsAnInactiveTreasuryToo(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->update(['active' => 0]);
        $this->byHand((int) $this->treasuries->accountOf($drawer), '25.00');

        $this->assertSame(CheckStatus::Review, $this->check('treasury_ties')->status);
    }

    public function testStockTies_PassesWhenEveryLocationTiesToItsMovements(): void
    {
        $itemId = $this->item('10.00');
        $this->buy([$this->purchaseLine($itemId, 0, '5', '10')]);

        $result = $this->check('stock_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['differences']);
    }

    public function testStockTies_FailsAndListsALocationWhoseAccountHoldsWhatNoMovementExplains(): void
    {
        $this->byHand($this->stockAccount(), '40.00');

        $result = $this->check('stock_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString('location ' . self::LOCATION_ID, $result->findings[0]);
        $this->assertStringContainsString('its account holds 40.0000, its movements add up to 0.0000, difference 40.0000', $result->findings[0]);
    }

    public function testStockTies_FailsForAJournalWhoseMovementsDisagreeWithItsLines(): void
    {
        $itemId = $this->item('10.00');
        $result = (new PostingService())->post(new JournalDraft(
            date: '2026-10-24',
            lines: [new JournalLineDraft($this->stockAccount(), debit: '50.00'), new JournalLineDraft($this->supplierAccount(), credit: '50.00')],
            sourceType: 'purchase',
            sourceId: 9306,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        (new StockLedger())->record('purchase', 9306, JournalEntryType::General, 1, '2026-10-24', 1, [new StockMovement($itemId, self::LOCATION_ID, '5', '40.00')], $result->journalId);

        $check = $this->check('stock_ties');

        $this->assertSame(CheckStatus::Fail, $check->status);
        $this->assertContains("journal #{$result->journalId} puts 50.0000 on the account of location " . self::LOCATION_ID . ' while its stock movements add up to 40.0000', $check->findings);
    }

    public function testStockTies_CountsADeletedLocationThatStillHoldsValue(): void
    {
        $location = $this->insertStockLocation('Closed warehouse');
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Closed warehouse');
        $this->byHand($this->stockAccountOf($location), '15.00');
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['deleted' => 1]);

        $result = $this->check('stock_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString("location {$location} (Closed warehouse)", $result->findings[0]);
    }

    public function testInterbranchTies_PassesWhenTransfersBetweenBranchesAreMatched(): void
    {
        [$first, $second, $manager] = $this->twoBranches();
        (new TreasuryTransfers())->transfer($first, $second, '100.00', '2026-10-21', '', $manager);

        $result = $this->check('interbranch_ties');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('0.0000', $result->data['difference']);
        $this->assertSame(2, $result->data['pairs']);
    }

    /**
     * @return array{int, int, int} A treasury in each of two branches, and an employee who may move money between them.
     */
    private function twoBranches(): array
    {
        $manager = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $second  = $this->createBranch('B2');
        $first   = $this->treasuries->create(['name' => 'First drawer', 'kind' => 'cash'], $manager);
        $other   = $this->treasuries->create(['name' => 'Second drawer', 'kind' => 'cash', 'branch_id' => $second], $manager);

        return [$first, $other, $manager];
    }

    public function testInterbranchTies_FailsWhenAJournalOnOneAccountHasNoCounterpartInTheOther(): void
    {
        $this->byHand($this->accountId('branches_receivable'), '50.00');

        $result = $this->check('interbranch_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['the accounts between branches offset to 50.0000 across the company instead of nothing'], $result->findings);
        $this->assertSame('50.0000', $result->data['difference']);
    }

    public function testInterbranchTies_FailsAndNamesAPairThatDoesNotAgree(): void
    {
        [$first, $second, $manager] = $this->twoBranches();
        (new TreasuryTransfers())->transfer($first, $second, '100.00', '2026-10-21', '', $manager);
        $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->accountId('branches_payable'))->update(['credit' => '60.0000']);

        $result = $this->check('interbranch_ties');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(3, $result->findings);
        $this->assertStringContainsString('branch HQ against branch B2: it is owed 100.0000 and owes 0.0000', implode("\n", $result->findings));
        $this->assertStringContainsString('branch B2 against branch HQ', implode("\n", $result->findings));
    }

    public function testInterbranchTies_IsSkippedBeforeTheMigrationOfTheSettlements(): void
    {
        $this->accountingDb()->query('RENAME TABLE ospos_accounting_interbranch_legs TO ospos_accounting_interbranch_legs_hold');

        $this->assertSame(CheckStatus::Skipped, $this->check('interbranch_ties')->status);
    }

    public function testInterbranchUnattributed_ListsTheJournalsThatNoTransferAccountsFor(): void
    {
        $this->byHand($this->accountId('branches_receivable'), '50.00');
        $this->byHand($this->accountId('branches_payable'), '20.00', false);

        $result = $this->check('interbranch_unattributed');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['branch 1, source manual: 2 journal(s)'], $result->findings);
        $this->assertSame(2, $result->data['journals']);
        $this->assertStringContainsString('never rewritten', $result->summary);
    }

    public function testInterbranchUnattributed_PassesForTransfersAndForJournalsOnOtherAccounts(): void
    {
        [$first, $second, $manager] = $this->twoBranches();
        (new TreasuryTransfers())->transfer($first, $second, '100.00', '2026-10-21', '', $manager);
        $this->byHand($this->accountId('cash'), '10.00');

        $this->assertSame(CheckStatus::Pass, $this->check('interbranch_unattributed')->status);
    }

    public function testInterbranchUnattributed_LeavesOutThePreviewsOnTheAccountsBetweenBranches(): void
    {
        $this->previewOfSource('sale', 990003, 1, '50.00', 'branches_receivable', 'opening_balance');

        $this->assertSame(CheckStatus::Pass, $this->check('interbranch_unattributed')->status);
    }

    public function testInterbranchUnattributed_LeavesEveryTransferJournalWhetherOrNotALegWasRecorded(): void
    {
        foreach ([TreasuryTransfers::SOURCE, TreasuryTransfers::SOURCE_RECEIPT, ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT] as $source) {
            $this->byHand($this->accountId('branches_receivable'), '50.00', true, $source);

            $this->assertSame(CheckStatus::Pass, $this->check('interbranch_unattributed')->status, $source);
        }
    }

    public function testInterbranchUnattributed_LeavesAJournalARecordedLegAccountsFor(): void
    {
        $this->byHand($this->accountId('branches_receivable'), '50.00');
        $journalId = (int) $this->accountingDb()->table('accounting_journals')->selectMax('journal_id', 'last')->get()->getRow()->last;
        $second    = $this->createBranch('B2');
        $this->accountingDb()->table('accounting_interbranch_legs')->insert(['journal_id' => $journalId, 'account_id' => $this->accountId('branches_receivable'), 'company_id' => 1, 'side' => 'receivable', 'counterpart_branch_id' => $second, 'document_type' => 'interbranch_settlement', 'document_id' => 1, 'revision' => 1]);

        $this->assertSame(CheckStatus::Pass, $this->check('interbranch_unattributed')->status);
    }

    public function testInterbranchUnattributed_LeavesTheReversalOfAJournalARecordedLegAccountsFor(): void
    {
        $this->byHand($this->accountId('branches_receivable'), '50.00');
        $journalId = (int) $this->accountingDb()->table('accounting_journals')->selectMax('journal_id', 'last')->get()->getRow()->last;
        $second    = $this->createBranch('B2');
        $this->accountingDb()->table('accounting_interbranch_legs')->insert(['journal_id' => $journalId, 'account_id' => $this->accountId('branches_receivable'), 'company_id' => 1, 'side' => 'receivable', 'counterpart_branch_id' => $second, 'document_type' => 'interbranch_settlement', 'document_id' => 1, 'revision' => 1]);
        $this->accountingDb()->table('accounting_journals')->insert([
            'company_id' => 1, 'branch_id' => 1, 'series' => 'manual', 'journal_date' => '2026-10-22', 'status' => 'posted', 'source_type' => 'reversal', 'source_id' => $journalId, 'entry_type' => 'general',
            'revision' => 1, 'origin' => 'user', 'currency_code' => 'SAR', 'total_debit' => '50.0000', 'total_credit' => '50.0000', 'reverses_journal_id' => $journalId, 'posted_at' => date('Y-m-d H:i:s'),
        ]);
        $reversalId = (int) $this->accountingDb()->insertID();
        $this->accountingDb()->table('accounting_journal_lines')->insertBatch([
            ['journal_id' => $reversalId, 'line_no' => 1, 'account_id' => $this->accountId('branches_receivable'), 'debit' => '0', 'credit' => '50.00'],
            ['journal_id' => $reversalId, 'line_no' => 2, 'account_id' => $this->accountId('opening_balance'), 'debit' => '50.00', 'credit' => '0'],
        ]);

        $this->assertSame(CheckStatus::Pass, $this->check('interbranch_unattributed')->status);
    }

    public function testInterbranchUnattributed_StillRunsBeforeTheMigrationOfTheSettlements(): void
    {
        $this->accountingDb()->query('RENAME TABLE ospos_accounting_interbranch_legs TO ospos_accounting_interbranch_legs_hold');
        $this->byHand($this->accountId('branches_receivable'), '50.00');

        $result = $this->check('interbranch_unattributed');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['journals']);
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->invoiceOf('100.00');
        $this->purchaseOf('500.00');
        $this->byHand($this->customerAccount(), '50.00');
        $this->byHand($this->accountId('branches_receivable'), '50.00');
        $before = $this->databaseFingerprint();

        $this->checks->run();

        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_LeavesOutThePreviewsOfABatch(): void
    {
        $this->saleWithCash();

        $before = array_map(static fn (CheckResult $result): array => $result->toArray(), $this->checks->run());

        $this->previewEverything();
        $this->assertPreviewsStand();

        $this->assertSame($before, array_map(static fn (CheckResult $result): array => $result->toArray(), $this->checks->run()));
    }
}
