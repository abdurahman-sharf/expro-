<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The dry run of the retroactive posting prints what a batch would do as JSON and writes nothing: not a
 * preview, not a row, and the database itself is asked to refuse a write while it runs.
 */
class AccountingBackfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use DatabaseFingerprintTrait;
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

    /**
     * @param array<string, string> $params
     *
     * @return array{int, string}
     */
    private function backfillRun(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:backfill', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $output): array
    {
        $json = substr($output, (int) strpos($output, '{'));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testRun_PrintsWhatTheRangeWouldPostAsJsonAndWritesNothing(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyExpense();

        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31']);

        $report = $this->decoded($output);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['read_only']);
        $this->assertSame(2, $report['summary']['documents']);
        $this->assertSame(2, $report['summary']['ready']);
        $this->assertSame(4, $report['summary']['journals']);
        $this->assertSame(['sales', 'expenses'], array_column($report['documents'], 'family'));
        $this->assertSame(['sale', 'sale_payment', 'sale'], array_column($report['documents'][0]['journals'], 'source'));
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_GivesTheFingerprintAPreviewOfTheSameRangeGets(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyPurchase('500', '2026-03-11');
        $this->legacyExpense();
        $this->legacyCashup();

        [, $output] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31']);

        $this->assertSame($this->previewed()->fingerprint, $this->decoded($output)['fingerprint']);
    }

    public function testRun_ListsTheRefusalsWithTheirReasonAndCountsThem(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['journal_date' => '2026-03-20']);

        [, $output] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31']);

        $report = $this->decoded($output);

        $this->assertSame(['before_opening' => 1], $report['summary']['reasons']);
        $this->assertSame('refused', $report['documents'][0]['outcome']);
    }

    public function testRun_LimitsTheListedDocumentsButNotTheSummary(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');

        [, $output] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31', 'limit' => '1']);

        $report = $this->decoded($output);

        $this->assertCount(1, $report['documents']);
        $this->assertSame(2, $report['summary']['documents']);
    }

    public function testRun_LooksOnlyAtTheFamiliesAndTheBranchAsked(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense();

        [, $output] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31', 'families' => 'expenses']);

        $this->assertSame(['expenses'], $this->decoded($output)['families']);
        $this->assertSame(1, $this->decoded($output)['summary']['documents']);

        [, $other] = $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31', 'branch' => (string) $this->createBranch()]);

        $this->assertSame(0, $this->decoded($other)['summary']['documents']);
    }

    public function testRun_RefusesARangeThatIsMissingOrNotADateAndAFamilyThatIsUnknown(): void
    {
        foreach ([[], ['from' => '2026-01-01'], ['from' => '2026-12-31', 'to' => '2026-01-01'], ['from' => '2026-01-01', 'to' => '2026-12-31', 'families' => 'nonsense'], ['from' => '2026-01-01', 'to' => '2026-12-31', 'limit' => 'x']] as $params) {
            [$exit] = $this->backfillRun($params);

            $this->assertSame(EXIT_USER_INPUT, $exit, json_encode($params));
        }
    }

    public function testRun_DoesNotPreviewABatchEvenWhenTheRangeHoldsDocuments(): void
    {
        $this->legacyExpense();

        $this->backfillRun(['from' => '2026-01-01', 'to' => '2026-12-31']);

        $this->assertSame(0, $this->countRows('accounting_backfill_batches'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('status', 'preview')->countAllResults());
    }
}
