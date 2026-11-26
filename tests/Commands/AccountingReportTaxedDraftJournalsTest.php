<?php

namespace Tests\Commands;

use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingReportTaxedDraftJournalsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
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
        $this->clearJournals();

        parent::tearDown();
    }

    private function runReport(): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:report_taxed_draft_journals');

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ListsTheDraftsWithATaxAndWhatTheyWillBeOutByAndChangesNothing(): void
    {
        $input                            = $this->journalInput('100.00');
        $input['lines'][1]['tax_code']    = 'VAT15';
        $input['lines'][1]['tax_percent'] = '15';
        $draftId                          = (new JournalService())->saveDraft($input);

        $output = $this->runReport();

        $this->assertStringContainsString('-15.0000', $output);
        $this->assertStringContainsString('1 draft(s) carry a tax on a line, 1 of them will not balance when posted. Nothing was changed.', $output);
        $this->assertSame('draft', $this->accountingDb()->table('accounting_journals')->where('journal_id', $draftId)->get()->getRow()->status);
    }

    public function testRun_AcceptsBooksWithoutSuchADraft(): void
    {
        $this->assertStringContainsString('No draft carries a tax on a line.', $this->runReport());
    }
}
