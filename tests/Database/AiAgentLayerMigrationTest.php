<?php

namespace Tests\Database;

use App\Database\Migrations\AiAgentLayer;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class AiAgentLayerMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new AiAgentLayer())->up();
        $this->clearJournals();
        $this->setConfigValue('accounting_ai_enabled', '0');

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function proposal(string $status = 'proposed', ?int $journalId = null): int
    {
        $this->accountingDb()->table('accounting_ai_proposals')->insert(['kind' => 'recurring', 'status' => $status, 'payload' => '{}', 'journal_id' => $journalId, 'dedupe_key' => hash('sha256', uniqid('', true))]);

        return (int) $this->accountingDb()->insertID();
    }

    public function testUp_GivesTheProposalsTableTheColumnsOfAProposal(): void
    {
        foreach (['title', 'rationale', 'template_args', 'proposer', 'proposal_date', 'fingerprint', 'dedupe_key', 'expires_at', 'reject_reason', 'stale_reason'] as $column) {
            $this->assertTrue($this->hasColumn('accounting_ai_proposals', $column), $column);
        }
    }

    public function testUp_CreatesTheFindingsTable(): void
    {
        $this->assertTrue($this->hasTable('accounting_ai_findings'));

        foreach (['rule_code', 'severity', 'finding_key', 'branch_id', 'subject_type', 'subject_id', 'occurred_on', 'template_args', 'evidence', 'status', 'decided_by', 'decided_at', 'decision_reason', 'detected_at'] as $column) {
            $this->assertTrue($this->hasColumn('accounting_ai_findings', $column), $column);
        }
    }

    public function testUp_StartsAProposalAsProposedAndRefusesAStatusOfNoneOfTheFive(): void
    {
        $id = $this->proposal();

        $this->assertSame('proposed', $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->status);

        $this->expectException(DatabaseException::class);

        $this->proposal('pending');
    }

    public function testUp_RefusesAnApprovedProposalWithoutAJournalAndAJournalOnAProposalThatIsNotApproved(): void
    {
        $journal = $this->manualJournalForMigration();

        foreach ([['approved', null], ['proposed', $journal]] as [$status, $journalId]) {
            try {
                $this->proposal($status, $journalId);
                $this->fail("A {$status} proposal with journal " . json_encode($journalId) . ' was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertGreaterThan(0, $this->proposal('approved', $journal));
    }

    public function testUp_KeepsOneProposalForAKeyAndOneForAJournal(): void
    {
        $journal = $this->manualJournalForMigration();
        $this->proposal('approved', $journal);

        try {
            $this->proposal('approved', $journal);
            $this->fail('Two proposals own the same journal.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->accountingDb()->table('accounting_ai_proposals')->insert(['kind' => 'recurring', 'payload' => '{}', 'dedupe_key' => str_repeat('a', 64)]);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_ai_proposals')->insert(['kind' => 'recurring', 'payload' => '{}', 'dedupe_key' => str_repeat('a', 64)]);
    }

    public function testUp_RegistersTheModuleAndTheTwoPermissionsAndGrantsThemToNobody(): void
    {
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', 'accounting_ai')->countAllResults());

        foreach ([AccountingGuard::AI_USE, AccountingGuard::AI_APPROVE] as $permissionId) {
            $this->assertSame('accounting_ai', $this->accountingDb()->table('permissions')->where('permission_id', $permissionId)->get()->getRow()->module_id);
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', $permissionId)->countAllResults());
            $this->assertContains($permissionId, AccountingGuard::SENSITIVE);
        }
    }

    public function testUp_WritesTheSwitchAsOff(): void
    {
        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'accounting_ai_enabled')->get()->getRow()->value);
    }

    public function testUp_IsSafeToRunTwiceAndLeavesWhatWasSetAndWhatWasWrittenAlone(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '1');
        $this->accountingDb()->table('grants')->insert(['permission_id' => AccountingGuard::AI_USE, 'person_id' => 1, 'menu_group' => '--']);
        $id = $this->proposal();

        (new AiAgentLayer())->up();
        (new AiAgentLayer())->up();

        $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', 'accounting_ai_enabled')->get()->getRow()->value);
        $this->assertSame(1, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::AI_USE)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::AI_USE)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', 'accounting_ai')->countAllResults());
        $this->assertSame('proposed', $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->status);

        $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::AI_USE)->delete();
    }

    public function testUp_TouchesNoJournalAndNoBalance(): void
    {
        $this->manualJournalForMigration();

        $before = $this->accountingDb()->table('accounting_journals')->countAllResults();

        (new AiAgentLayer())->up();

        $this->assertSame($before, $this->accountingDb()->table('accounting_journals')->countAllResults());
    }

    public function testDown_RefusesOnceAProposalOrAFindingExistsAndRemovesWhatWasAddedWhenNoneDoes(): void
    {
        $this->proposal();

        try {
            (new AiAgentLayer())->down();
            $this->fail('A proposal exists.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('backup', $exception->getMessage());
        }

        $this->assertTrue($this->hasTable('accounting_ai_findings'));

        $this->clearJournals();
        (new AiAgentLayer())->down();

        $this->assertFalse($this->hasTable('accounting_ai_findings'));
        $this->assertFalse($this->hasColumn('accounting_ai_proposals', 'title'));
        $this->assertNull($this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::AI_APPROVE)->get()->getRow());
        $this->assertNull($this->accountingDb()->table('modules')->where('module_id', 'accounting_ai')->get()->getRow());
        $this->assertNull($this->accountingDb()->table('app_config')->where('key', 'accounting_ai_enabled')->get()->getRow());
    }

    private function manualJournalForMigration(): int
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        return (new JournalService())->postNew($this->journalInput(), null);
    }
}
