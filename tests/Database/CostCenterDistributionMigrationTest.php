<?php

namespace Tests\Database;

use App\Database\Migrations\CostCenterDistribution as CostCenterDistributionMigration;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * The tables behind the distribution of a journal line, the default distribution of an account and the
 * tags and tax note of a line: additive, safe to run again, no existing line touched, rows that go with
 * their line and rows that keep a cost center from being deleted.
 *
 * @internal
 */
final class CostCenterDistributionMigrationTest extends CIUnitTestCase
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
        $this->resetJournalSettings();
    }

    protected function tearDown(): void
    {
        (new CostCenterDistributionMigration())->up();
        $this->releaseOpenTransactions();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @return array{line: int, journal: int, center: int, second: int}
     */
    private function journalWithTwoCenters(): array
    {
        $centers = new CostCenterService();
        $first   = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $second  = $centers->create(['code' => 'B', 'name' => 'Beta']);
        $input   = $this->journalInput();

        $input['lines'][1]['distribution'] = [['cost_center_id' => $first, 'percent' => '60'], ['cost_center_id' => $second, 'percent' => '40']];

        $journalId = (new JournalService())->postNew($input);
        $line      = (int) $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->where('line_no', 2)->get()->getRow()->line_id;

        return ['line' => $line, 'journal' => $journalId, 'center' => $first, 'second' => $second];
    }

    public function testUp_CreatesTheThreeTablesAndWritesNothingToThem(): void
    {
        foreach (['accounting_journal_line_distributions', 'accounting_account_cost_centers', 'accounting_journal_line_meta'] as $table) {
            $this->assertTrue($this->accountingDb()->tableExists($this->accountingDb()->prefixTable($table)), $table);
            $this->assertSame(0, $this->countRows($table), $table);
        }
    }

    public function testUp_CanRunAgainAndLeavesTheRowsThatAreThere(): void
    {
        $made = $this->journalWithTwoCenters();

        (new CostCenterDistributionMigration())->up();
        (new CostCenterDistributionMigration())->up();

        $this->assertSame(2, $this->countRows('accounting_journal_line_distributions'));
        $this->assertSame(2, (int) $this->accountingDb()->table('accounting_journal_line_distributions')->where('line_id', $made['line'])->countAllResults());
    }

    public function testUp_LeavesTheLinesAndTheJournalsAsTheyAre(): void
    {
        $journalId = (new JournalService())->postNew($this->journalInput());
        $before    = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResultArray();
        $header    = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRowArray();

        (new CostCenterDistributionMigration())->up();

        $this->assertSame($before, $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResultArray());
        $this->assertSame($header, $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRowArray());
        $this->assertSame(0, $this->countRows('accounting_journal_line_distributions'));
    }

    public function testDown_RefusesWhileAnyLineCarriesADistributionAndDropsTheTablesWhenNone(): void
    {
        $this->journalWithTwoCenters();

        try {
            (new CostCenterDistributionMigration())->down();
            $this->fail('The migration was rolled back over a distribution.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('backup', $refusal->getMessage());
        }

        $this->assertSame(2, $this->countRows('accounting_journal_line_distributions'));

        $this->clearJournals();
        $migration = new CostCenterDistributionMigration();
        $migration->down();

        $this->assertFalse($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('accounting_journal_line_distributions'), false));

        $migration->up();

        $this->assertTrue($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('accounting_journal_line_distributions'), false));
    }

    public function testDown_RefusesWhileAnAccountHasADefaultDistribution(): void
    {
        $centers = new CostCenterService();
        $center  = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $centers->setDefaults($this->accountId('cash'), [['cost_center_id' => $center, 'percent' => '100']]);

        $this->expectException(RuntimeException::class);

        (new CostCenterDistributionMigration())->down();
    }

    public function testUp_KeepsACenterFromBeingDeletedWhileADistributionNamesIt(): void
    {
        $made = $this->journalWithTwoCenters();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_cost_centers')->where('cost_center_id', $made['center'])->delete();
    }

    public function testUp_RejectsARepeatedCenterAZeroPercentAndANegativeAmountOnADistribution(): void
    {
        $made = $this->journalWithTwoCenters();

        foreach ([
            ['line_id' => $made['line'], 'cost_center_id' => $made['center'], 'percent' => '10', 'amount' => '1'],
            ['line_id' => $made['line'], 'cost_center_id' => (new CostCenterService())->create(['code' => 'C', 'name' => 'Gamma']), 'percent' => '0', 'amount' => '0'],
            ['line_id' => $made['line'], 'cost_center_id' => (new CostCenterService())->create(['code' => 'D', 'name' => 'Delta']), 'percent' => '5', 'amount' => '-1'],
        ] as $row) {
            try {
                $this->accountingDb()->table('accounting_journal_line_distributions')->insert($row);
                $this->fail('A row the table must refuse was written.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, $this->countRows('accounting_journal_line_distributions'));
    }

    public function testUp_DeletesTheDefaultsOfADeletedAccountAndKeepsTheCenter(): void
    {
        $centers = new CostCenterService();
        $center  = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $account = $this->customAccount();

        $centers->setDefaults($account, [['cost_center_id' => $center, 'percent' => '100']]);

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->delete();

        $this->assertSame(0, $this->countRows('accounting_account_cost_centers'));
        $this->assertNotNull($centers->find($center));
    }

    public function testUp_DeletesTheDistributionWithItsLineWhenADraftIsSavedAgain(): void
    {
        $centers = new CostCenterService();
        $first   = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $second  = $centers->create(['code' => 'B', 'name' => 'Beta']);
        $service = new JournalService();
        $input   = $this->journalInput();

        $input['lines'][0]['distribution'] = [['cost_center_id' => $first, 'percent' => '50'], ['cost_center_id' => $second, 'percent' => '50']];

        $draftId = $service->saveDraft($input);

        $this->assertSame(2, $this->countRows('accounting_journal_line_distributions'));

        $service->saveDraft($this->journalInput(), null, $draftId);

        $this->assertSame(0, $this->countRows('accounting_journal_line_distributions'));
    }
}
