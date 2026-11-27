<?php

namespace Tests\Commands;

use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingPostingShadowDiffsTest extends CIUnitTestCase
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

        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();

        parent::tearDown();
    }

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:posting_shadow_diffs ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_SaysTheEngineAgreedWhenNothingWasRecorded(): void
    {
        $this->assertStringContainsString('The engine agreed with every journal posted in shadow mode.', $this->runCommand());
    }

    public function testRun_ListsTheLinesOnlyOneSideHasAndChangesNothing(): void
    {
        $legacy = new JournalDraft(date: '2026-10-21', lines: [new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')], sourceType: 'expense', sourceId: 12, branchId: 1);
        $engine = new JournalDraft(date: '2026-10-21', lines: [new JournalLineDraft(6, debit: '100'), new JournalLineDraft(9, credit: '100')], sourceType: 'expense', sourceId: 12, branchId: 1);
        (new ShadowComparison())->compare('expense', $legacy, $engine, null, 'expense', 12, 1);

        $output = $this->runCommand('expense');

        $this->assertStringContainsString('expense expense 12 revision 1, branch 1', $output);
        $this->assertStringContainsString('only the code: 5|debit|100.0000|1|||', $output);
        $this->assertStringContainsString('only the engine: 6|debit|100.0000|1|||', $output);
        $this->assertStringContainsString('1 difference(s).', $output);
        $this->assertSame(1, $this->countRows('accounting_posting_shadow_diffs'));
        $this->assertStringContainsString('The engine agreed', $this->runCommand('sale'));
    }
}
