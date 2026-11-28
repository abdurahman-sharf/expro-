<?php

namespace Tests\Commands;

use App\Libraries\Accounting\Engine\PostingGaps;
use App\Libraries\Accounting\Engine\PostingMode;
use App\Libraries\Accounting\Engine\PostingModes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingPostingModeTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'expense', 'mode' => 'shadow']);
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'posting_mode')->delete();

        parent::tearDown();
    }

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:posting_mode ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ListsTheModeOfEachOperation(): void
    {
        $output = $this->runCommand();

        $this->assertStringContainsString('expense: shadow', $output);
        $this->assertStringContainsString('Any other operation: legacy.', $output);
    }

    public function testRun_SwitchesAnOperationInTheNameOfAnEmployee(): void
    {
        $this->assertStringContainsString('expense: engine', $this->runCommand('expense engine --employee 1'));
        $this->assertSame(PostingMode::Engine, (new PostingModes())->of('expense'));
        $this->assertSame('1', (string) $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'posting_mode')->get()->getRow()->employee_id);
    }

    public function testRun_RequiresAModeItKnowsAndAnEmployeeThatExists(): void
    {
        $this->assertStringContainsString('Name an operation and a mode', $this->runCommand('expense fast --employee 1'));
        $this->assertStringContainsString('Name the employee who switches the mode', $this->runCommand('expense engine'));
        $this->assertStringContainsString('Name the employee who switches the mode', $this->runCommand('expense engine --employee 987654'));
        $this->assertSame(PostingMode::Shadow, (new PostingModes())->of('expense'));
    }

    public function testRun_RejectsTheEngineForAnOperationWithAnOpenGap(): void
    {
        $output = $this->runCommand('purchase engine --employee 1');

        $this->assertStringContainsString(lang('Accounting.posting_engine_gap_open', ['purchase', implode('; ', PostingGaps::of('purchase'))]), $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertNotSame(PostingMode::Engine, (new PostingModes())->of('purchase'));
    }
}
