<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\LineAnalysis;
use App\Libraries\Accounting\ManualJournalFacts;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ManualJournalFactsTest extends CIUnitTestCase
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
        $this->resetBranches();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue(JournalSettings::LINE_TAX, '1');
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(JournalSettings::LINE_TAX, '0');
        $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'manual', 'mode' => 'shadow']);
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * A journal of random lines that balances once the tax the books add is counted, the same for the
     * same seed.
     *
     * @return array{date: string, description: string, lines: list<array<string, mixed>>}
     */
    private function randomJournal(int $seed): array
    {
        mt_srand($seed);

        $accounts = [$this->accountId('expenses_default'), $this->accountId('sales_revenue'), $this->accountId('purchases'), $this->accountId('other_payables')];
        $lines    = [];
        $balance  = 0;

        for ($i = 0, $count = mt_rand(1, 4); $i < $count; $i++) {
            $amount  = mt_rand(100, 99999);
            $debit   = mt_rand(0, 1) === 1;
            $percent = [0, 5, 15][mt_rand(0, 2)];
            $line    = ['account_id' => $accounts[mt_rand(0, 3)], 'debit' => $debit ? sprintf('%d.%02d', intdiv($amount, 100), $amount % 100) : '', 'credit' => $debit ? '' : sprintf('%d.%02d', intdiv($amount, 100), $amount % 100)];
            $tax     = 0;

            if ($percent > 0) {
                $included             = mt_rand(0, 1) === 1;
                $line['tax_code']     = 'VAT' . $percent;
                $line['tax_percent']  = (string) $percent;
                $line['tax_included'] = $included ? '1' : '0';
                $tax                  = $included ? 0 : (int) round($amount * $percent / 100);
            }

            $balance += ($debit ? 1 : -1) * ($amount + $tax);
            $lines[]  = $line;
        }

        $cash    = abs($balance);
        $lines[] = ['account_id' => $this->accountId('cash'), 'debit' => $balance < 0 ? sprintf('%d.%02d', intdiv($cash, 100), $cash % 100) : '', 'credit' => $balance >= 0 ? sprintf('%d.%02d', intdiv(max($cash, 1), 100), max($cash, 1) % 100) : ''];

        if ($balance === 0) {
            $lines[] = ['account_id' => $this->accountId('cash'), 'debit' => '0.01', 'credit' => ''];
        }

        return ['date' => '2026-10-21', 'description' => 'Random ' . $seed, 'lines' => $lines];
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeForRandomManualJournalsWithTaxes(): void
    {
        $service = new JournalService();
        $posted  = 0;

        for ($seed = 1; $seed <= 60; $seed++) {
            $service->postNew($this->randomJournal($seed));
            $posted++;
        }

        $this->assertSame(60, $posted);
        $this->assertSame(0, $this->countRows('accounting_posting_shadow_diffs'), 'Every journal agreed in shadow');
    }

    public function testOf_LetsTheEnginePostTheSameLinesInTheSameOrderAsTheCode(): void
    {
        $service = new JournalService();
        $lines   = fn (int $journalId): array => array_map(
            static fn (object $line): string => implode('|', [$line->line_no, $line->account_id, $line->debit, $line->credit, $line->cost_center_id ?? '']),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResult(),
        );

        for ($seed = 100; $seed < 115; $seed++) {
            $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'manual', 'mode' => 'legacy']);
            $legacy = $service->postNew($this->randomJournal($seed));

            $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'manual', 'mode' => 'engine']);
            $engine = $service->postNew($this->randomJournal($seed));

            $this->assertSame($lines($legacy), $lines($engine), "seed {$seed}");
        }
    }

    public function testOf_PutsTheTaxOfALineOnTheTaxPaidForADebitAndTheTaxCollectedForACreditRightAfterIt(): void
    {
        $facts = ManualJournalFacts::of([
            ['account_id' => $this->accountId('expenses_default'), 'cost_center_id' => null, 'debit' => '100.0000', 'credit' => '0.0000', 'analysis' => new LineAnalysis(null, [], [], ['code' => 'VAT15', 'percent' => '15.0000', 'included' => false, 'amount' => '15.0000'])],
            ['account_id' => $this->accountId('sales_revenue'), 'cost_center_id' => null, 'debit' => '0.0000', 'credit' => '115.0000', 'analysis' => new LineAnalysis(null, [], [], ['code' => 'VAT15', 'percent' => '15.0000', 'included' => true, 'amount' => '15.0000'])],
            ['account_id' => $this->accountId('cash'), 'cost_center_id' => null, 'debit' => '0.0000', 'credit' => '15.0000'],
        ], '2026-10-21', 1, 'Typed', 1);

        $draft = (new PostingEngine())->build($facts);

        $this->assertSame(['manual', null, 'Typed', 'user'], [$draft->sourceType, $draft->sourceId, $draft->description, $draft->origin->value]);
        $this->assertSame(
            [
                $this->accountId('expenses_default') . ':100.0000:0', $this->accountId('tax_input') . ':15.0000:0',
                $this->accountId('sales_revenue') . ':0:100.0000', $this->accountId('tax_output') . ':0:15.0000',
                $this->accountId('cash') . ':0:15.0000',
            ],
            array_map(static fn ($line): string => $line->accountId . ':' . $line->debit . ':' . $line->credit, $draft->lines),
        );
        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($draft, $draft));
    }
}
