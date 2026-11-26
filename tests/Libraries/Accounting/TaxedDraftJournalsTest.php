<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TaxedDraftJournals;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class TaxedDraftJournalsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private JournalService $journals;

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
        $this->journals = new JournalService();
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(JournalSettings::LINE_TAX, '0');
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $tax
     */
    private function draft(array $tax, string $debit = '100.00', string $credit = '100.00'): int
    {
        $input                      = $this->journalInput('100.00');
        $input['lines'][0]['debit'] = $debit;
        $input['lines'][1]          = ['account_id' => $this->accountId('sales_revenue'), 'debit' => '', 'credit' => $credit] + $tax;

        return $this->journals->saveDraft($input);
    }

    public function testList_HoldsADraftWhoseTaxOnTopOfALineWillPutItOutWhenPosted(): void
    {
        $draftId = $this->draft(['tax_code' => 'VAT15', 'tax_percent' => '15']);

        $this->assertSame(
            [[$draftId, '100.0000', '100.0000', '0.0000', '15.0000', '-15.0000']],
            array_map(static fn (array $row): array => [$row['journal_id'], $row['debit'], $row['credit'], $row['tax_on_debit'], $row['tax_on_credit'], $row['difference_when_posted']], (new TaxedDraftJournals())->list()),
        );
    }

    public function testList_SaysADraftWhoseOtherSideCoversTheTaxWillBalance(): void
    {
        $this->draft(['tax_code' => 'VAT15', 'tax_percent' => '15'], '115.00');

        $this->assertSame('0.0000', (new TaxedDraftJournals())->list()[0]['difference_when_posted']);
    }

    public function testList_SaysADraftWhoseTaxIsInTheAmountOfItsLineWillBalance(): void
    {
        $this->draft(['tax_code' => 'VAT15', 'tax_percent' => '15', 'tax_included' => '1']);

        $listed = (new TaxedDraftJournals())->list();

        $this->assertSame(['0.0000', '0.0000'], [$listed[0]['tax_on_credit'], $listed[0]['difference_when_posted']]);
    }

    public function testList_LeavesOutDraftsWithoutATaxAndJournalsThatWerePosted(): void
    {
        $this->journals->saveDraft($this->journalInput('50.00'));
        $this->journals->postDraft($this->draft(['tax_code' => 'VAT15', 'tax_percent' => '15'], '115.00'));

        $this->assertSame([], (new TaxedDraftJournals())->list());
    }

    public function testList_AgreesWithWhatPostingTheDraftRefusesBy(): void
    {
        $draftId = $this->draft(['tax_code' => 'VAT15', 'tax_percent' => '15']);
        $listed  = (new TaxedDraftJournals())->list()[0]['difference_when_posted'];

        try {
            $this->journals->postDraft($draftId);
            $this->fail('The draft was posted although its other side does not cover the tax.');
        } catch (PostingException $failure) {
            $this->assertSame(ltrim($listed, '-'), $failure->context['difference']);
        }
    }
}
