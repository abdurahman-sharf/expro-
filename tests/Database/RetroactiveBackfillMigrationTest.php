<?php

namespace Tests\Database;

use App\Database\Migrations\RetroactiveBackfill;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class RetroactiveBackfillMigrationTest extends CIUnitTestCase
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
        (new RetroactiveBackfill())->up();
        $this->clearJournals();

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

    public function testUp_AddsTheColumnsOfABatchAndTheTableOfItsDocuments(): void
    {
        foreach (['branch_id', 'name', 'end_date', 'families', 'chunk_size', 'cursor_family', 'cursor_id', 'fingerprint', 'document_count', 'ready_count', 'refused_count', 'journal_count', 'total_debit', 'approved_count'] as $column) {
            $this->assertTrue($this->hasColumn('accounting_backfill_batches', $column), $column);
        }

        $this->assertTrue($this->hasTable('accounting_backfill_items'));
    }

    public function testUp_RegistersThePermissionForThePeriodsScreenAndGrantsItToNobody(): void
    {
        $row = $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->get()->getRow();

        $this->assertNotNull($row);
        $this->assertSame('accounting_periods', $row->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->countAllResults());
    }

    public function testUp_WritesTheSettingThatKeepsTheApprovalOffAsOff(): void
    {
        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'accounting_backfill_enabled')->get()->getRow()->value);
    }

    public function testUp_IsSafeToRunTwiceAndLeavesWhatWasSetAlone(): void
    {
        $this->setConfigValue('accounting_backfill_enabled', '1');
        $this->accountingDb()->table('grants')->insert(['permission_id' => AccountingGuard::BACKFILL_MANAGE, 'person_id' => 1, 'menu_group' => '--']);

        (new RetroactiveBackfill())->up();
        (new RetroactiveBackfill())->up();

        $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', 'accounting_backfill_enabled')->get()->getRow()->value);
        $this->assertSame(1, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->countAllResults());

        $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->delete();
        $this->setConfigValue('accounting_backfill_enabled', '0');
    }

    public function testUp_TouchesNoJournalAndNoBatchThatExists(): void
    {
        $this->accountingDb()->table('accounting_backfill_batches')->insert(['name' => 'kept', 'status' => 'preview', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'families' => 'sales']);

        (new RetroactiveBackfill())->up();

        $this->assertSame('kept', $this->accountingDb()->table('accounting_backfill_batches')->get()->getRow()->name);
    }

    public function testDown_RefusesOnceABatchExistsAndRemovesWhatWasAddedWhenNoneDoes(): void
    {
        $this->accountingDb()->table('accounting_backfill_batches')->insert(['name' => 'kept', 'status' => 'preview', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'families' => 'sales']);

        try {
            (new RetroactiveBackfill())->down();
            $this->fail('A batch exists.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('backup', $exception->getMessage());
        }

        $this->assertTrue($this->hasTable('accounting_backfill_items'));

        $this->clearJournals();
        (new RetroactiveBackfill())->down();

        $this->assertFalse($this->hasTable('accounting_backfill_items'));
        $this->assertFalse($this->hasColumn('accounting_backfill_batches', 'families'));
        $this->assertNull($this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BACKFILL_MANAGE)->get()->getRow());
    }
}
