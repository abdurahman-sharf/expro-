<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\BankAccountPlacement;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\PaymentTreasuryStamping;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\HistoricalDataChecks;
use App\Libraries\Accounting\Readiness\MigrationChecks;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\MigrationHistoryFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The protections of data from before the accounting layer, read the way the commands read them:
 * the purchase discounts the migration cannot tell apart, the bank accounts and their details, and
 * the payments that name no treasury. Nothing is changed by reading them.
 */
class HistoricalDataChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use DatabaseFingerprintTrait;
    use MigrationHistoryFixtureTrait;

    private const DISCOUNT_MIGRATION = '20261029000000';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private HistoricalDataChecks $checks;
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
        (new LegacyPurchaseDiscount())->clearResolution();

        $this->checks     = new HistoricalDataChecks($this->accountingDb(), new MigrationChecks($this->accountingDb()));
        $this->treasuries = new TreasuryService();
        $this->treasuries->ensureMain();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach (['bank_name', 'account_number'] as $column) {
            if (! $db->query('SHOW COLUMNS FROM ospos_treasuries LIKE ?', [$column])->getNumRows() && $db->query('SHOW COLUMNS FROM ospos_treasuries LIKE ?', [$column . '_hold'])->getNumRows()) {
                $db->query("ALTER TABLE ospos_treasuries RENAME COLUMN {$column}_hold TO {$column}");
            }
        }

        $this->restoreMigrations();
        (new LegacyPurchaseDiscount())->clearResolution();
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

    private function savedAs(string $savedAt): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeWithGrants([]), 'comment' => '', 'receiving_time' => $savedAt]);

        $receivingId           = (int) $db->insertID();
        $this->receivingIds[]  = $receivingId;

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->createTestItem(HAS_STOCK),
            'line'               => 1,
            'quantity_purchased' => '10',
            'receiving_quantity' => '1',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '50.00',
            'discount'           => '20.00',
            'discount_type'      => FIXED,
            'item_location'      => 1,
        ]);

        return $receivingId;
    }

    private function legacyBank(string $name, ?string $bankName = null, ?string $accountNumber = null): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => 'bank', 'bank_name' => $bankName, 'account_number' => $accountNumber]);

        $treasuryId = (int) $this->accountingDb()->insertID();

        (new EntityAccountService())->ensure(EntityAccountService::TREASURY, $treasuryId, $name);

        return $treasuryId;
    }

    private function hideBankDetailsColumns(): void
    {
        foreach (['bank_name', 'account_number'] as $column) {
            $this->accountingDb()->query("ALTER TABLE ospos_treasuries RENAME COLUMN {$column} TO {$column}_hold");
        }
    }

    private function saleWithCashPayment(): int
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
    }

    public function testRun_GivesTheFourChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['purchase_discount', 'bank_placement', 'bank_details', 'payment_stamping'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['E38-SCAN', 'E40-BANK-DETAILS', 'E40-BANK-DETAILS', 'E41-STAMP'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testPurchaseDiscount_PassesWithoutScanningWhenTheMigrationAlreadyRan(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));

        $result = $this->check('purchase_discount');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertTrue($result->data['migration_applied']);
        $this->assertStringContainsString('was applied on', $result->summary);
    }

    public function testPurchaseDiscount_PassesWhenNoLineCarriesAFixedDiscountOverSeveralUnits(): void
    {
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $result = $this->check('purchase_discount');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('nothing to mark', $result->summary);
        $this->assertSame(0, $result->data['lines']);
        $this->assertFalse($result->data['migration_applied']);
    }

    public function testPurchaseDiscount_FailsAndListsTheLinesNoEvidenceDecides(): void
    {
        $early = $this->savedAs('2026-10-01 09:00:00');
        $late  = $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $result = $this->check('purchase_discount');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString("purchase #{$late} line 1", $result->findings[0]);
        $this->assertStringNotContainsString("purchase #{$early}", $result->findings[0]);
        $this->assertSame(1, $result->data['blocked']);
        $this->assertSame(1, $result->data['undetermined']);
        $this->assertSame(1, $result->data['would_mark']);
        $this->assertSame(1, $result->data['verdicts']['legacy_by_date']);
        $this->assertSame(1, $result->data['verdicts']['undetermined']);
        $this->assertSame(0, $result->data['verdicts']['per_unit_by_books']);
        $this->assertNull($result->apply, 'The owner decides these lines: no command decides them.');
    }

    public function testPurchaseDiscount_ShowsTheMoneyTheTwoReadingsOfAnUndecidedLineDifferBy(): void
    {
        $this->savedAs('2026-10-01 09:00:00');
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $result = $this->check('purchase_discount');

        $this->assertSame('180.00', $result->data['at_stake'], '10 units at 20.00 each is 200.00 against 20.00 for the whole line.');
        $this->assertSame('20.00', $result->data['would_mark_discount'], 'Only the early line is marked, with the 20.00 it carries.');
        $this->assertStringContainsString('the two readings differ by 180.00', $result->findings[0]);
        $this->assertStringContainsString('differ by 180.00 in the purchase totals', $result->summary);
    }

    public function testPurchaseDiscount_SumsTheMoneyAtStakeOfEveryUndecidedLineWhateverTheSignOfItsUnits(): void
    {
        $first  = $this->savedAs(date('Y-m-d H:i:s'));
        $second = $this->savedAs(date('Y-m-d H:i:s'));
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $second)->update(['quantity_purchased' => '-3', 'discount' => '5.00']);
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $result = $this->check('purchase_discount');

        $this->assertSame('200.00', $result->data['at_stake'], '180.00 for 10 units at 20.00, and 20.00 for a return of 3 units at 5.00.');
        $this->assertStringContainsString("purchase #{$first} line 1", $result->findings[0]);
        $this->assertStringContainsString('the two readings differ by 20.00', $result->findings[1]);
    }

    public function testPurchaseDiscount_ShowsTheSameLinesAndDiscountBeforeAndAfterTheMigrationMarksThem(): void
    {
        $this->savedAs('2026-10-01 09:00:00');
        $this->savedAs('2026-10-02 09:00:00');
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $before = $this->check('purchase_discount');

        (new LegacyPurchaseDiscount())->apply();
        $this->restoreMigrations();

        $after = $this->check('purchase_discount');

        $this->assertSame(2, $before->data['would_mark']);
        $this->assertSame('40.00', $before->data['would_mark_discount']);
        $this->assertTrue($after->data['migration_applied']);
        $this->assertSame($before->data['would_mark'], $after->data['marked_lines']);
        $this->assertSame($before->data['would_mark_discount'], $after->data['marked_discount']);
    }

    public function testPurchaseDiscount_CountsNoMarkedLineWhenTheMigrationMarkedNone(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));

        $result = $this->check('purchase_discount');

        $this->assertSame(0, $result->data['marked_lines']);
        $this->assertSame('0.00', $result->data['marked_discount']);
    }

    public function testPurchaseDiscount_ChangesNoPurchaseLine(): void
    {
        $early = $this->savedAs('2026-10-01 09:00:00');
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        $before = $this->databaseFingerprint();

        $this->check('purchase_discount');

        $this->assertDatabaseUnchanged($before);
        $this->assertSame(FIXED, (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $early)->get()->getRow()->discount_type);
    }

    public function testPurchaseDiscount_ReviewsWhatARecordedDecisionSettles(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        (new LegacyPurchaseDiscount())->resolve(LegacyPurchaseDiscount::ALL);

        $result = $this->check('purchase_discount');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(0, $result->data['blocked']);
        $this->assertSame(1, $result->data['undetermined']);
        $this->assertSame(['kind' => 'all', 'before' => null], $result->data['resolution']);
        $this->assertCount(1, $result->findings);
    }

    public function testPurchaseDiscount_PassesWhenTheDateDecidesEveryLine(): void
    {
        $this->savedAs('2026-10-01 09:00:00');
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $result = $this->check('purchase_discount');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('the migration marks 1 and leaves 0', $result->summary);
        $this->assertSame(0, $result->data['undetermined']);
    }

    public function testBankPlacement_FailsAndNamesTheCommandThatMovesTheAccounts(): void
    {
        $bank = $this->legacyBank('Old bank');

        $result = $this->check('bank_placement');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString("treasury {$bank} (Old bank), account 1201", $result->findings[0]);
        $this->assertSame('accounting:bank_accounts --move', $result->applyCommand());
        $this->assertSame(1, $result->data['pending']);
    }

    public function testBankPlacement_PassesOnceTheAccountsAreUnderTheBanks(): void
    {
        $this->legacyBank('Old bank');
        $placement = new BankAccountPlacement();
        $placement->openRouting();
        $placement->move();

        $result = $this->check('bank_placement');

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function testBankPlacement_ChangesNoAccountAndNoJournalLine(): void
    {
        $this->legacyBank('Old bank');
        $before = $this->databaseFingerprint();

        $this->check('bank_placement');

        $this->assertDatabaseUnchanged($before);
    }

    public function testBankDetails_ListsTheBankAccountsThatLackTheNameOrTheNumberAndWhichOfThem(): void
    {
        $bare     = $this->legacyBank('Bare bank');
        $nameless = $this->legacyBank('Nameless bank', null, '123456');
        $numberless = $this->legacyBank('Numberless bank', 'Gulf Bank', null);
        $this->treasuries->create(['name' => 'Complete bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '987654']);

        $result = $this->check('bank_details');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame([
            "treasury {$bare} (Bare bank) lacks the bank name and the account number",
            "treasury {$nameless} (Nameless bank) lacks the bank name",
            "treasury {$numberless} (Numberless bank) lacks the account number",
        ], $result->findings);
        $this->assertSame(3, $result->data['incomplete']);
        $this->assertNull($result->apply);
    }

    public function testBankDetails_TreatsABlankNameAsMissing(): void
    {
        $blank = $this->legacyBank('Blank bank', '', '123456');

        $this->assertSame(["treasury {$blank} (Blank bank) lacks the bank name"], $this->check('bank_details')->findings);
    }

    public function testBankDetails_TreatsABlankAccountNumberAsMissing(): void
    {
        $blank = $this->legacyBank('Blank number bank', 'Al Rajhi', '');

        $this->assertSame(["treasury {$blank} (Blank number bank) lacks the account number"], $this->check('bank_details')->findings);
    }

    public function testBankDetails_PassesWhenEveryBankAccountHasBoth(): void
    {
        $this->treasuries->create(['name' => 'Complete bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '987654']);

        $this->assertSame(CheckStatus::Pass, $this->check('bank_details')->status);
    }

    public function testBankDetails_IgnoresACashTreasury(): void
    {
        $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);

        $this->assertSame(CheckStatus::Pass, $this->check('bank_details')->status);
    }

    public function testBankChecks_AreSkippedWithTheCountOfBankTreasuriesBeforeTheBankMigration(): void
    {
        $this->legacyBank('Old bank');
        $this->legacyBank('Older bank');
        $this->hideBankDetailsColumns();

        $placement = $this->check('bank_placement');
        $details   = $this->check('bank_details');

        $this->assertSame(CheckStatus::Skipped, $placement->status);
        $this->assertSame(CheckStatus::Skipped, $details->status);
        $this->assertSame(2, $details->data['bank_treasuries']);
        $this->assertStringContainsString('2 bank treasuries exist', $details->summary);
    }

    public function testBankChecks_SayOneBankTreasuryExistsInTheSingular(): void
    {
        $this->legacyBank('Old bank');
        $this->hideBankDetailsColumns();

        $this->assertStringContainsString('1 bank treasury exists', $this->check('bank_details')->summary);
    }

    public function testPaymentStamping_FailsAndListsThePaymentsThatNameNoTreasury(): void
    {
        $salePaymentId = $this->saleWithCashPayment();
        $purchaseId    = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '4', '50')]);
        $supplierPayId = $this->payPurchase($purchaseId, '60.00');

        $result = $this->check('payment_stamping');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertContains("sales_payments #{$salePaymentId} would take treasury " . $this->treasuries->ensureMain(), $result->findings);
        $this->assertContains("receivings_payments #{$supplierPayId} would take treasury " . $this->treasuries->ensureMain(), $result->findings);
        $this->assertSame(2, $result->data['stamp']);
        $this->assertSame('accounting:stamp_payments --apply', $result->applyCommand());
    }

    public function testPaymentStamping_WritesNothing(): void
    {
        $paymentId = $this->saleWithCashPayment();
        $before    = $this->databaseFingerprint();

        $this->check('payment_stamping');

        $this->assertDatabaseUnchanged($before);
        $this->assertNull($this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
    }

    public function testPaymentStamping_PassesAfterThePaymentsAreStamped(): void
    {
        $this->saleWithCashPayment();
        (new PaymentTreasuryStamping())->stamp();

        $this->assertSame(CheckStatus::Pass, $this->check('payment_stamping')->status);
    }

    public function testPaymentStamping_PassesForAPaymentThatNamesItsTreasury(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $item   = $this->item('10.00');
        $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);

        $this->assertSame(CheckStatus::Pass, $this->check('payment_stamping')->status);
    }

    public function testPaymentStamping_ReviewsAPaymentThatCannotBeStampedAndDoesNotFail(): void
    {
        $paymentId = $this->saleWithCashPayment();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.accounts')->update(['routing_type' => 'per_item']);

        $result = $this->check('payment_stamping');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertContains("sales_payments #{$paymentId} cannot be stamped: account_differs", $result->findings);
        $this->assertSame(0, $result->data['stamp']);
        $this->assertSame(1, $result->data['skipped']);
    }

    public function testPaymentStamping_PassesWhenNoPaymentWentIntoATreasury(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->check('payment_stamping')->status);
    }
}
