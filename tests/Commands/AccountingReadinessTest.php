<?php

namespace Tests\Commands;

use App\Commands\AccountingReadiness;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\Readiness\DeploymentChecks;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\InstallerPasswordFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\MigrationHistoryFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The readiness command reads a database, lists what is not ready, writes nothing unless it is
 * asked to apply, and says by its exit code whether the database is ready.
 */
class AccountingReadinessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
    use EmployeeFixtureTrait;
    use InstallerPasswordFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;
    use MigrationHistoryFixtureTrait;

    private const DISCOUNT_MIGRATION = '20261029000000';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $purchaseIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '0');
        (new LegacyPurchaseDiscount())->clearResolution();
        $this->changeInstallerPassword();
    }

    protected function tearDown(): void
    {
        $this->restoreInstallerPassword();
        $this->restoreMigrations();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->purchaseIds as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->purchaseIds = [];

        (new LegacyPurchaseDiscount())->clearResolution();
        $this->stopSelling();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function readiness(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:readiness', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    private function savedAs(string $savedAt): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeWithGrants([]), 'comment' => '', 'receiving_time' => $savedAt]);

        $receivingId       = (int) $db->insertID();
        $this->purchaseIds[] = $receivingId;

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

    private function unstampedPayment(): int
    {
        $this->startSelling();
        (new TreasuryService())->ensureMain();

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
    }

    private function driftedPayment(): int
    {
        $paymentId = $this->unstampedPayment();
        $elsewhere = $this->customAccount('1201', '120199', 'Cash moved elsewhere');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $elsewhere]);

        return $paymentId;
    }

    private function parentCodeOf(int $treasuryId): string
    {
        $db      = $this->accountingDb();
        $account = $db->table('accounting_accounts')->where(['entity_type' => 'treasury', 'entity_id' => $treasuryId])->get()->getRow();

        return (string) $db->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code;
    }

    private function stampOf(int $paymentId): ?string
    {
        return $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $output): array
    {
        return json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function checkOf(array $report, string $id): array
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("The report has no check {$id}.");
    }

    public function testRun_ExitsZeroOnACleanDatabase(): void
    {
        [$exit, $output] = $this->readiness();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: READY.', $output);
        $this->assertDoesNotMatchRegularExpression('/(FAIL|ERROR)\s+[A-Z0-9-]+:/', $output);
    }

    public function testRun_ExitsOneAndChangesNoRowWhenPurchaseLinesAreMixed(): void
    {
        $early = $this->savedAs('2026-10-01 09:00:00');
        $late  = $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('RESULT: NOT READY.', $output);
        $this->assertMatchesRegularExpression('/FAIL\s+E38-SCAN/', $output);
        $this->assertStringContainsString("purchase #{$late} line 1", $output);
        $this->assertStringNotContainsString("purchase #{$early} line 1", $output);
        $this->assertDatabaseUnchanged($before, 'Reading a mixed set of purchase lines must change no row.');
        $this->assertSame(FIXED, (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $early)->get()->getRow()->discount_type);
        $this->assertNull((new LegacyPurchaseDiscount())->resolution());
    }

    public function testRun_ExitsZeroWhenARecordedDecisionSettlesTheUndecidedLines(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        (new LegacyPurchaseDiscount())->resolve(LegacyPurchaseDiscount::NONE);

        [$exit, $output] = $this->readiness();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/REVIEW\s+E38-SCAN/', $output);
    }

    public function testRun_ListsBankTreasuriesThatLackTheirNameOrNumber(): void
    {
        $bare    = $this->legacyBank('Bare bank');
        $nameless = $this->legacyBank('Nameless bank', null, '123456');
        (new TreasuryService())->create(['name' => 'Complete bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '987654']);
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FAIL\s+E40-BANK-DETAILS: Bank name and account number/', $output);
        $this->assertStringContainsString("treasury {$bare} (Bare bank) lacks the bank name and the account number", $output);
        $this->assertStringContainsString("treasury {$nameless} (Nameless bank) lacks the bank name", $output);
        $this->assertStringNotContainsString('Complete bank', $output);
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_ListsThePaymentsThatWouldDriftAndWritesNone(): void
    {
        $paymentId = $this->driftedPayment();
        $before    = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FAIL\s+E41-STAMP/', $output);
        $this->assertStringContainsString("sales_payments #{$paymentId} would take treasury", $output);
        $this->assertStringContainsString('accounting:stamp_payments --apply', $output);
        $this->assertNull($this->stampOf($paymentId));
        $this->assertDatabaseUnchanged($before, 'Listing an unstamped payment must write nothing.');
    }

    public function testRun_ExitsZeroWhileAPaymentNamesNoTreasuryAndStaysWhereItsRoutingPoints(): void
    {
        $paymentId = $this->unstampedPayment();
        $before    = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/PASS\s+E41-STAMP/', $output);
        $this->assertStringContainsString('sales_payments: 1 payment(s) name no treasury and are where their way of payment is routed', $output);
        $this->assertNull($this->stampOf($paymentId));
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_ExitsZeroAfterAChequeBouncesAfterItsDepositAFirstExtraTreasuryIsCreatedAndAJournalIsWrittenByHandOnATreasury(): void
    {
        $this->startSelling();
        $this->startCheques();

        $cheque = $this->receiveCheque('300.00', $this->databaseToday());
        $this->chequeService->deposit($cheque, $this->databaseToday(), $this->chequeClerk);
        $this->chequeService->reject($cheque, $this->databaseToday(), $this->chequeClerk);

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        $treasuries = new TreasuryService();
        $result     = (new PostingService())->post(new JournalDraft(
            date: $this->databaseToday(),
            lines: [new JournalLineDraft($this->accountId('opening_balance'), debit: '7.00'), new JournalLineDraft((int) $treasuries->accountOf($treasuries->ensureMain()), credit: '7.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            sourceId: null,
            employeeId: $this->chequeClerk,
            currencyCode: 'SAR',
        ));

        $this->assertNotNull($result);

        [$exit, $output] = $this->readiness(['json' => null, 'no-header' => null]);
        $report          = $this->decoded($output);

        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'cheque_in_dep_rejected')->where('source_id', $cheque)->countAllResults());
        $this->assertNull($this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->treasury_id);
        $this->assertSame('pass', $this->checkOf($report, 'source_keys')['status']);
        $this->assertSame('pass', $this->checkOf($report, 'payment_stamping')['status']);
        $this->assertSame('review', $this->checkOf($report, 'treasury_ties')['status']);
        $this->assertSame(0, $exit);
    }

    public function testRun_ExitsOneWhileAnAccountStillHasThePasswordTheInstallerGaveIt(): void
    {
        $this->restoreInstallerPassword();
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FAIL\s+ADMIN-DEFAULT-PASSWORD/', $output);
        $this->assertStringContainsString('the account admin still has the installer password', $output);
        $this->assertStringNotContainsString(DeploymentChecks::INSTALLER_PASSWORD, $output);
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_ReviewsTheFirstRunSettingsOfAFreshInstallationWithoutFailing(): void
    {
        $company = (string) $this->accountingDb()->table('app_config')->where('key', 'company')->get()->getRow()->value;
        $this->setConfigValue('company', 'Open Source Point of Sale');

        try {
            [$exit, $output] = $this->readiness();
        } finally {
            $this->setConfigValue('company', $company);
        }

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/REVIEW\s+FIRST-RUN-SETTINGS/', $output);
        $this->assertStringContainsString('the company name (company) is still "Open Source Point of Sale"', $output);
        $this->assertMatchesRegularExpression('/PASS\s+ADMIN-DEFAULT-PASSWORD/', $output);
    }

    public function testRun_PrintsOneJsonDocumentWithNothingElse(): void
    {
        [$exit, $output] = $this->readiness(['json' => null]);

        $report = $this->decoded($output);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('{', trim($output));
        $this->assertTrue($report['ready']);
        $this->assertSame(0, $report['exit_code']);
        $this->assertTrue($report['read_only']);
        $this->assertSame('post-migration', $report['phase']);
        $this->assertCount(33, $report['checks']);
        $this->assertCount(8, $report['runbook']);
        $this->assertSame(array_sum($report['counts']), count($report['checks']));
    }

    public function testRun_PutsTheFindingsOfAFailedCheckInTheJsonAndExitsOne(): void
    {
        $bare = $this->legacyBank('Bare bank');

        [$exit, $output] = $this->readiness(['json' => null]);

        $report = $this->decoded($output);
        $check  = $this->checkOf($report, 'bank_details');

        $this->assertSame(1, $exit);
        $this->assertFalse($report['ready']);
        $this->assertSame(1, $report['exit_code']);
        $this->assertSame('fail', $check['status']);
        $this->assertSame("treasury {$bare} (Bare bank) lacks the bank name and the account number", $check['findings'][0]);
        $this->assertSame(1, $check['data']['incomplete']);
    }

    public function testRun_RefusesToApplyWhileAskedForJson(): void
    {
        $paymentId = $this->driftedPayment();

        [$exit, $output] = $this->readiness(['json' => null, 'apply' => null]);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertStringNotContainsString('{', $output);
        $this->assertNull($this->stampOf($paymentId));
    }

    public function testRun_StampsThePaymentsOnlyWhenAskedToApplyAndChangesNoJournalOrBalance(): void
    {
        $paymentId = $this->driftedPayment();
        $lines     = $this->accountingDb()->table('accounting_journal_lines')->select('journal_id, account_id, debit, credit')->orderBy('line_id', 'ASC')->get()->getResultArray();
        $journals  = $this->countRows('accounting_journals');

        [$exit, $output] = $this->readiness(['apply' => null]);

        $this->assertStringContainsString('--apply: accounting:stamp_payments --apply', $output);
        $this->assertMatchesRegularExpression("/sales_payments #{$paymentId}: treasury \\d+\\./", $output);
        $this->assertNotNull($this->stampOf($paymentId));
        $this->assertMatchesRegularExpression('/PASS\s+E41-STAMP/', $output);
        $this->assertSame($lines, $this->accountingDb()->table('accounting_journal_lines')->select('journal_id, account_id, debit, credit')->orderBy('line_id', 'ASC')->get()->getResultArray());
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testRun_MovesTheBankAccountsOnlyWhenAskedToApplyAndChangesNoJournalLine(): void
    {
        $bank  = $this->legacyBank('Old bank', 'Gulf Bank', '123456');
        $lines = $this->accountingDb()->table('accounting_journal_lines')->select('journal_id, account_id, debit, credit')->orderBy('line_id', 'ASC')->get()->getResultArray();

        [$exit] = $this->readiness();

        $this->assertSame(1, $exit);
        $this->assertSame('1201', $this->parentCodeOf($bank));

        [, $output] = $this->readiness(['apply' => null]);

        $this->assertStringContainsString('--apply: accounting:bank_accounts --move', $output);
        $this->assertMatchesRegularExpression("/Treasury {$bank}: account 1201\\d+ moved to 1202\\d+\\./", $output);
        $this->assertMatchesRegularExpression('/PASS\s+E40-BANK-DETAILS: Bank accounts under/', $output);
        $this->assertSame('1202', $this->parentCodeOf($bank));
        $this->assertSame($lines, $this->accountingDb()->table('accounting_journal_lines')->select('journal_id, account_id, debit, credit')->orderBy('line_id', 'ASC')->get()->getResultArray());
    }

    public function testRun_AppliesEveryCommandThatCanClearAFailureInTheOrderOfTheChecks(): void
    {
        $paymentId = $this->driftedPayment();
        $bank      = $this->legacyBank('Old bank', 'Gulf Bank', '123456');

        $this->assertSame('1201', $this->parentCodeOf($bank));

        [, $output] = $this->readiness(['apply' => null]);

        $moved   = strpos($output, '--apply: accounting:bank_accounts --move');
        $stamped = strpos($output, '--apply: accounting:stamp_payments --apply');

        $this->assertNotFalse($moved, 'The bank accounts are moved.');
        $this->assertNotFalse($stamped, 'The payments are stamped.');
        $this->assertLessThan($stamped, $moved, 'The command of the check that comes first runs first.');
        $this->assertSame('1202', $this->parentCodeOf($bank));
        $this->assertNotNull($this->stampOf($paymentId));
    }

    public function testRun_PrintsTheReportBeforeItAppliesAndAgainAfterwards(): void
    {
        $paymentId = $this->driftedPayment();

        [, $output] = $this->readiness(['apply' => null]);

        $before  = strpos($output, 'ACCOUNTING READINESS (before --apply)');
        $applied = strpos($output, '--apply: accounting:stamp_payments --apply');
        $after   = strpos($output, 'ACCOUNTING READINESS (after --apply)');

        $this->assertNotFalse($before, 'The report of what was found comes first.');
        $this->assertNotFalse($applied);
        $this->assertNotFalse($after, 'The report of what is left comes last.');
        $this->assertLessThan($applied, $before);
        $this->assertLessThan($after, $applied);
        $this->assertMatchesRegularExpression('/FAIL\s+E41-STAMP/', substr($output, $before, $applied - $before));
        $this->assertStringContainsString("sales_payments #{$paymentId} would take treasury", substr($output, $before, $applied - $before));
        $this->assertMatchesRegularExpression('/PASS\s+E41-STAMP/', substr($output, $after));
        $this->assertSame(1, substr_count($output, 'RUNBOOK (in this order)'), 'The runbook ends the output once.');
    }

    public function testRun_AppliesNothingWhenNothingFailed(): void
    {
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness(['apply' => null]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--apply: nothing the existing commands could clear.', $output);
        $this->assertStringContainsString('ACCOUNTING READINESS (before --apply)', $output);
        $this->assertStringNotContainsString('(after --apply)', $output, 'Nothing ran, so there is nothing to report again.');
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_DoesNotApplyWhatItWasNotAskedToApplyForAFailureNoCommandClears(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->readiness(['apply' => null]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--apply: nothing the existing commands could clear.', $output);
        $this->assertDatabaseUnchanged($before, 'The purchase lines are decided by the owner: --apply never decides them.');
    }

    public function testRun_PrintsOnlyTheFirstFindingsOfACheckUnlessAskedForAll(): void
    {
        foreach (['First bank', 'Second bank', 'Third bank'] as $name) {
            $this->legacyBank($name);
        }

        [, $limited] = $this->readiness(['limit' => '1']);
        [, $all]     = $this->readiness(['limit' => '0']);
        [, $default] = $this->readiness();

        $this->assertStringContainsString('First bank', $limited);
        $this->assertStringNotContainsString('Second bank', $limited);
        $this->assertStringContainsString('... and 2 more', $limited);
        $this->assertStringContainsString('Third bank', $all);
        $this->assertStringNotContainsString('more (--limit 0', $all);
        $this->assertStringContainsString('Third bank', $default);
    }

    public function testRun_ReadsTheLimitWrittenWithAnEqualSign(): void
    {
        foreach (['First bank', 'Second bank'] as $name) {
            $this->legacyBank($name);
        }

        [, $output] = $this->readiness(['limit=1' => null]);

        $this->assertStringContainsString('... and 1 more', $output);
    }

    public function testRun_KeepsTheJsonWholeWhateverTheLimit(): void
    {
        foreach (['First bank', 'Second bank', 'Third bank'] as $name) {
            $this->legacyBank($name);
        }

        [, $output] = $this->readiness(['json' => null, 'limit' => '1']);

        $this->assertCount(3, $this->checkOf($this->decoded($output), 'bank_details')['findings']);
    }

    public function testRun_RefusesALimitThatIsNotANumber(): void
    {
        [$exit, $output] = $this->readiness(['limit' => 'many']);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertStringNotContainsString('RESULT', $output);
    }

    public function testRun_RefusesALimitWithNoValueOrANegativeOne(): void
    {
        foreach (['no value' => ['limit' => null], 'a negative number' => ['limit' => '-1'], 'nothing after the equal sign' => ['limit=' => null], 'a fraction' => ['limit' => '1.5']] as $case => $params) {
            [$exit, $output] = $this->readiness($params);

            $this->assertSame(EXIT_USER_INPUT, $exit, "A limit with {$case} is refused.");
            $this->assertStringNotContainsString('RESULT', $output, "A limit with {$case} prints no report.");
        }
    }

    public function testRun_SaysInItsDescriptionAndInTheRunbookDocumentThatARefusedOptionExitsSeven(): void
    {
        $command  = new AccountingReadiness(service('logger'), service('commands'));
        $document = (string) file_get_contents(ROOTPATH . 'docs/accounting/readiness-runbook.md');

        $this->assertSame(7, EXIT_USER_INPUT);
        $this->assertStringContainsString('and 7 for an option it refuses', $command->description);
        $this->assertStringContainsString('`7` (`EXIT_USER_INPUT`)', $document);
    }

    public function testRun_AcceptsZeroAsTheLimitForAll(): void
    {
        [$exit, $output] = $this->readiness(['limit' => '0']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: READY.', $output);
    }

    public function testRun_EndsWithTheRunbookInOrderAndFlagsThePendingMigrationThatRewritesData(): void
    {
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        [, $output] = $this->readiness();

        $runbook = substr($output, (int) strpos($output, 'RUNBOOK (in this order)'));

        $this->assertStringContainsString('RUNBOOK (in this order)', $output);
        $this->assertGreaterThan(strpos($output, 'RESULT:'), strpos($output, 'RUNBOOK (in this order)'));
        $this->assertMatchesRegularExpression('/\n1\. Back up.*\n\n2\. Readiness before.*\n\n3\. Decide.*\n\n4\. Run the migrations.*\n\n5\. Post-checks.*\n\n6\. Review who holds.*\n\n7\. Turn accounting on.*\n\n8\. Roll back/s', $runbook);
        $this->assertStringContainsString(self::DISCOUNT_MIGRATION . ' LegacyFixedPurchaseDiscount [DATA]', $runbook);
        $this->assertStringContainsString('phase pre-migration', $output);
    }

    public function testRun_SaysAReadOnlyTransactionWasUsed(): void
    {
        [, $output] = $this->readiness();

        $this->assertStringContainsString('Every check ran inside a read-only transaction: nothing was written.', $output);
    }

    public function testRun_ChangesNoRowOnAnyOfTheCleanOrFailingDatabases(): void
    {
        $this->legacyBank('Bare bank');
        $this->savedAs(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
        $before = $this->databaseFingerprint();

        $this->readiness();
        $this->readiness(['json' => null]);

        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_SaysTheDatabaseDoesNotEnforceReadingWhenACallerTransactionIsOpen(): void
    {
        $db = Database::connect();
        $db->transStart();

        try {
            [, $output] = $this->readiness();
        } finally {
            $db->transRollback();
            $db->resetTransStatus();
        }

        $this->assertStringContainsString('The connection could not open a read-only transaction: the checks only read, but the database does not enforce it.', $output);
        $this->assertStringNotContainsString('Every check ran inside a read-only transaction', $output);
    }
}
