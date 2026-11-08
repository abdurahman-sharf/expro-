<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineAnalysis;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\LineAnalysis;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * What a journal line carries beyond its amount: cleaned from what a person typed, written beside the
 * lines, read back (a line with no row of its own is charged in full to its single cost center),
 * copied to a reversal, and given the default distribution of its account.
 *
 * @internal
 */
final class JournalLineAnalysisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private JournalLineAnalysis $analysis;
    private CostCenterService $centers;

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
        $this->analysis = new JournalLineAnalysis();
        $this->centers  = new CostCenterService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->resetJournalSettings();

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    /**
     * @return array{journal: int, lines: list<int>}
     */
    private function journal(string $amount = '100.00', string $sourceType = 'sale', int $sourceId = 1): array
    {
        $journalId = (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: $amount), new JournalLineDraft($this->accountId('sales_revenue'), credit: $amount)],
            sourceType: $sourceType,
            sourceId: $sourceId,
            currencyCode: 'SAR',
        ))->journalId;

        return ['journal' => $journalId, 'lines' => array_map(static fn (array $row): int => (int) $row['line_id'], $this->accountingDb()->table('accounting_journal_lines')->select('line_id')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResultArray())];
    }

    /**
     * @param list<int> $lineIds
     *
     * @return list<object>
     */
    private function linesFor(array $lineIds): array
    {
        return $this->accountingDb()->table('accounting_journal_lines')->whereIn('line_id', $lineIds)->orderBy('line_no')->get()->getResult();
    }

    public function testClean_ReadsTheSingleCenterOfALineAndRequiresItToBeActive(): void
    {
        $center = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);

        $this->assertSame($center, $this->analysis->clean(['cost_center_id' => (string) $center], 1, '10', true)->costCenterId);
        $this->assertNull($this->analysis->clean(['cost_center_id' => ''], 1, '10', true)->costCenterId);
        $this->assertNull($this->analysis->clean([], 1, '10', true)->costCenterId);

        $this->centers->deactivate($center);

        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->analysis->clean(['cost_center_id' => $center], 1, '10', true));
        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->analysis->clean(['cost_center_id' => 999999], 1, '10', true));
    }

    public function testClean_HoldsASingleRowAtOneHundredPercentAsTheSingleCenterAndKeepsAnyOtherDistribution(): void
    {
        $a = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b = $this->centers->create(['code' => 'B', 'name' => 'Beta']);

        $single = $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '100.0000']]], 1, '10', true);

        $this->assertSame($a, $single->costCenterId);
        $this->assertSame([], $single->distribution);

        $split = $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '25'], ['cost_center_id' => $b, 'percent' => '75']]], 1, '10', true);

        $this->assertNull($split->costCenterId);
        $this->assertSame(['2.5000', '7.5000'], array_column($split->distribution, 'amount'));

        $this->setConfigValue(JournalSettings::OVER_100, '1');

        $over = $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '150']]], 1, '10', true);

        $this->assertNull($over->costCenterId);
        $this->assertSame(['15.0000'], array_column($over->distribution, 'amount'));
    }

    public function testClean_SkipsBlankRowsOfAForm(): void
    {
        $a = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);

        $analysis = $this->analysis->clean(['cost_center_id' => '', 'distribution' => [['cost_center_id' => '', 'percent' => '', 'amount' => ''], ['cost_center_id' => $a, 'percent' => '100']]], 1, '10', true);

        $this->assertSame($a, $analysis->costCenterId);
        $this->assertSame($a, $this->analysis->clean(['cost_center_id' => $a, 'distribution' => [['cost_center_id' => '', 'percent' => '', 'amount' => '']]], 1, '10', true)->costCenterId);
    }

    public function testClean_TakesTagsFromATextOrAListTrimsThemAndDropsRepeats(): void
    {
        $this->setConfigValue(JournalSettings::LINE_TAGS, '1');

        $this->assertSame(['rent', 'Office', 'تجربة'], $this->analysis->clean(['tags' => ' rent ,  Office؛ Rent;تجربة,'], 1, '10', true)->tags);
        $this->assertSame(['one', 'two'], $this->analysis->clean(['tags' => ['one', ' two ', 'ONE', '']], 1, '10', true)->tags);
        $this->assertSame([], $this->analysis->clean(['tags' => ' , ; '], 1, '10', true)->tags);
        $this->assertSame([], $this->analysis->clean([], 1, '10', true)->tags);
        $this->assertSame(['a b'], $this->analysis->clean(['tags' => "a \t  b"], 1, '10', true)->tags);
    }

    public function testClean_RejectsTagsThatAreTooLongTooManyOrNotText(): void
    {
        $this->setConfigValue(JournalSettings::LINE_TAGS, '1');

        $this->assertFailsWith(AccountingError::LineTagInvalid, fn () => $this->analysis->clean(['tags' => str_repeat('x', JournalLineAnalysis::TAG_MAX + 1)], 1, '10', true));
        $this->assertFailsWith(AccountingError::LineTagInvalid, fn () => $this->analysis->clean(['tags' => implode(',', range(1, JournalLineAnalysis::TAGS_MAX + 1))], 1, '10', true));
        $this->assertFailsWith(AccountingError::LineTagInvalid, fn () => $this->analysis->clean(['tags' => [['nested']]], 1, '10', true));
        $this->assertFailsWith(AccountingError::LineTagInvalid, fn () => $this->analysis->clean(['tags' => "bad\x07tag"], 1, '10', true));
        $this->assertCount(JournalLineAnalysis::TAGS_MAX, $this->analysis->clean(['tags' => implode(',', range(1, JournalLineAnalysis::TAGS_MAX))], 1, '10', true)->tags);
        $this->assertCount(1, $this->analysis->clean(['tags' => str_repeat('x', JournalLineAnalysis::TAG_MAX)], 1, '10', true)->tags);
    }

    public function testClean_RefusesTagsAndATaxNoteWhileTheirSettingsAreOffButNotAnEmptyOne(): void
    {
        $this->assertFailsWith(AccountingError::LineTagsOff, fn () => $this->analysis->clean(['tags' => 'x'], 3, '10', true));
        $this->assertFailsWith(AccountingError::LineTaxOff, fn () => $this->analysis->clean(['tax_code' => 'VAT', 'tax_percent' => '5'], 3, '10', true));

        $this->assertSame([], $this->analysis->clean(['tags' => '', 'tax_code' => '', 'tax_percent' => ''], 3, '10', true)->tags);
        $this->assertNull($this->analysis->clean(['tags' => '', 'tax_code' => '', 'tax_percent' => ''], 3, '10', true)->tax);

        try {
            $this->analysis->clean(['tags' => 'x'], 3, '10', true);
        } catch (AccountingException $exception) {
            $this->assertSame(3, $exception->context['line']);
        }
    }

    public function testClean_WorksOutTheTaxNoteOfALineAndRequiresACodeAndARate(): void
    {
        $this->setConfigValue(JournalSettings::LINE_TAX, '1');

        $this->assertSame(['code' => 'VAT15', 'percent' => '15.0000', 'included' => false, 'amount' => '15.0000'], $this->analysis->clean(['tax_code' => 'VAT15', 'tax_percent' => '15'], 1, '100.00', true)->tax);
        $this->assertSame(['code' => 'VAT15', 'percent' => '15.0000', 'included' => true, 'amount' => '13.0400'], $this->analysis->clean(['tax_code' => 'VAT15', 'tax_percent' => '15', 'tax_included' => '1'], 1, '100.00', true)->tax);
        $this->assertSame('0.0000', $this->analysis->clean(['tax_code' => 'EXEMPT', 'tax_percent' => '0'], 1, '100.00', true)->tax['amount']);
        $this->assertFailsWith(AccountingError::LineTaxInvalid, fn () => $this->analysis->clean(['tax_code' => 'VAT15'], 1, '100.00', true));
        $this->assertFailsWith(AccountingError::LineTaxInvalid, fn () => $this->analysis->clean(['tax_percent' => '15'], 1, '100.00', true));
    }

    public function testApply_ReplacesWhatALineCarriesAndKeepsTheSingleCenterOfTheLineInStep(): void
    {
        $a = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b = $this->centers->create(['code' => 'B', 'name' => 'Beta']);
        $made = $this->journal();
        $line = $made['lines'][1];

        $split = $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => $b, 'percent' => '50']]], 2, '100.00', true);
        $this->analysis->apply($line, $split);

        $this->assertNull($this->linesFor([$line])[0]->cost_center_id);
        $this->assertCount(2, $this->analysis->load($this->linesFor([$line]))[$line]->distribution);

        $this->analysis->apply($line, new LineAnalysis($b));

        $this->assertSame((string) $b, (string) $this->linesFor([$line])[0]->cost_center_id);
        $this->assertSame([], $this->analysis->load($this->linesFor([$line]))[$line]->distribution);
        $this->assertSame(0, $this->countRows('accounting_journal_line_distributions'));

        $this->analysis->apply($line, new LineAnalysis());

        $this->assertNull($this->linesFor([$line])[0]->cost_center_id);
    }

    public function testLoad_ReadsALineWithNoRowAsChargedInFullToItsSingleCenterOrToNone(): void
    {
        $a    = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $made = $this->journal();

        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $made['lines'][0])->update(['cost_center_id' => $a]);

        $loaded = $this->analysis->load($this->linesFor($made['lines']));

        $this->assertSame($a, $loaded[$made['lines'][0]]->costCenterId);
        $this->assertSame([], $loaded[$made['lines'][0]]->distribution);
        $this->assertTrue($loaded[$made['lines'][0]]->hasCharge());
        $this->assertFalse($loaded[$made['lines'][1]]->hasCharge());
        $this->assertSame([], $this->analysis->load([]));
    }

    public function testStore_WritesTheRowsByLineNumberAndNothingForALineWithNothingToKeep(): void
    {
        $a    = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b    = $this->centers->create(['code' => 'B', 'name' => 'Beta']);
        $made = $this->journal();
        $this->setConfigValue(JournalSettings::LINE_TAGS, '1');

        $this->analysis->store($made['journal'], [
            1 => new LineAnalysis($a),
            2 => $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '30'], ['cost_center_id' => $b, 'percent' => '70']], 'tags' => 'sale'], 2, '100.00', true),
        ]);

        $this->assertSame(2, $this->countRows('accounting_journal_line_distributions'));
        $this->assertSame(1, $this->countRows('accounting_journal_line_meta'));
        $this->assertNull($this->linesFor($made['lines'])[0]->cost_center_id);
        $this->assertSame(['sale'], $this->analysis->load($this->linesFor($made['lines']))[$made['lines'][1]]->tags);
    }

    public function testCopy_GivesTheLinesOfAReversalTheRowsOfTheLinesTheyMirrorInOrder(): void
    {
        $a    = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b    = $this->centers->create(['code' => 'B', 'name' => 'Beta']);
        $from = $this->journal('100.00', 'sale', 1);
        $to   = $this->journal('100.00', 'sale', 2);

        $this->analysis->apply($from['lines'][1], $this->analysis->clean(['distribution' => [['cost_center_id' => $a, 'percent' => '40'], ['cost_center_id' => $b, 'percent' => '60']]], 2, '100.00', true));
        $this->centers->deactivate($b);

        $this->analysis->copy($from['journal'], $to['journal']);

        $copied = $this->analysis->load($this->linesFor($to['lines']));

        $this->assertSame([], $copied[$to['lines'][0]]->distribution);
        $this->assertSame([['cost_center_id' => $a, 'percent' => '40.0000', 'amount' => '40.0000'], ['cost_center_id' => $b, 'percent' => '60.0000', 'amount' => '60.0000']], $copied[$to['lines'][1]]->distribution);
    }

    public function testApplyDefaults_DistributesTheLinesOfAnAccountThatHasADefaultAndLeavesTheOthers(): void
    {
        $a    = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b    = $this->centers->create(['code' => 'B', 'name' => 'Beta']);
        $this->centers->setDefaults($this->accountId('sales_revenue'), [['cost_center_id' => $a, 'percent' => '30'], ['cost_center_id' => $b, 'percent' => '70']]);
        $this->centers->setDefaults($this->accountId('cash'), [['cost_center_id' => $b, 'percent' => '100']]);
        $made = $this->journal();

        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $made['lines'][0])->update(['cost_center_id' => $a]);

        $this->analysis->applyDefaults($made['journal']);

        $loaded = $this->analysis->load($this->linesFor($made['lines']));

        $this->assertSame($a, $loaded[$made['lines'][0]]->costCenterId);
        $this->assertSame(['30.0000', '70.0000'], array_column($loaded[$made['lines'][1]]->distribution, 'amount'));
    }

    public function testApplyDefaults_HoldsADefaultOfOneCenterInTheLineAndSkipsADefaultWithACenterSwitchedOff(): void
    {
        $a = $this->centers->create(['code' => 'A', 'name' => 'Alpha']);
        $b = $this->centers->create(['code' => 'B', 'name' => 'Beta']);
        $this->centers->setDefaults($this->accountId('sales_revenue'), [['cost_center_id' => $a, 'percent' => '100']]);
        $this->centers->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => $b, 'percent' => '50']]);
        $this->centers->deactivate($b);
        $made = $this->journal();

        $this->analysis->applyDefaults($made['journal']);

        $loaded = $this->analysis->load($this->linesFor($made['lines']));

        $this->assertSame($a, $loaded[$made['lines'][1]]->costCenterId);
        $this->assertFalse($loaded[$made['lines'][0]]->hasCharge());
    }
}
