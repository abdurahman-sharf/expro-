<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChartCodeViolations;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ChartCodeViolationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

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

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    protected function tearDown(): void
    {
        $this->reinstallChart();

        parent::tearDown();
    }

    public function testList_AcceptsTheChartANewCompanyStartsWith(): void
    {
        $this->assertSame([], (new ChartCodeViolations())->list());
    }

    public function testList_HoldsAnAccountWhoseCodeDoesNotStartWithTheCodeOfItsParentAndSaysWhetherItHasLines(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_accounts')->where('system_key', 'payables')->update(['code' => '2211']);
        $db->table('accounting_accounts')->where('system_key', 'other_payables')->update(['code' => '2213']);

        (new PostingService())->post($this->draftWithLines([new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('other_payables'), credit: '10.00')]));

        $this->assertSame(
            [['2211', '211', false], ['2213', '211', true]],
            array_map(static fn (array $row): array => [$row['code'], $row['parent_code'], $row['has_journal_lines']], (new ChartCodeViolations())->list()),
        );
    }

    public function testList_HoldsAnAccountWhoseCodeIsTheCodeOfItsParentOrShorter(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->update(['code' => '120']);

        $this->assertSame(['120'], array_column((new ChartCodeViolations())->list(), 'code'));
    }
}
