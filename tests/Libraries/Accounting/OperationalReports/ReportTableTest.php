<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * @internal
 */
final class ReportTableTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    public function testRefused_HoldsNoFigureAndSaysWhy(): void
    {
        $table = ReportTable::refused(ReportScope::resolve(null, null), '2026-01-01', '2026-01-31', 'Accounting_operational_reports.profit_foreign_docs', ['POS 3 (USD)']);

        $this->assertTrue($table->isRefused());
        $this->assertSame('Accounting_operational_reports.profit_foreign_docs', $table->refusal);
        $this->assertSame(['POS 3 (USD)'], $table->refusalArguments);
        $this->assertSame([[], [], [], []], [$table->columns, $table->blocks, $table->total, $table->ties]);
        $this->assertSame(['2026-01-01', '2026-01-31'], [$table->from, $table->to]);
    }

    public function testIsRefused_IsFalseForAReportThatWasMade(): void
    {
        $table = new ReportTable(ReportScope::resolve(null, null), null, '2026-01-31', [], [], []);

        $this->assertFalse($table->isRefused());
        $this->assertNull($table->refusal);
    }
}
