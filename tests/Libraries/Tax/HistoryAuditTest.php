<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\HistoryAudit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class HistoryAuditTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var list<int>
     */
    private array $sales = [];

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        if ($this->sales !== []) {
            $db->table('sales')->whereIn('sale_id', $this->sales)->update(['return_of_sale_id' => null]);
            $db->table('sales')->whereIn('sale_id', $this->sales)->delete();
            $this->sales = [];
        }

        $db->table('company_tax_profile')->update(['ksa_mode' => 0, 'ksa_mode_effective_at' => null]);

        parent::tearDown();
    }

    private function sale(string $time, int $type = SALE_TYPE_POS, ?int $returnOf = null): int
    {
        $db = Database::connect();
        $db->table('sales')->insert(['sale_time' => $time, 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => $type, 'return_of_sale_id' => $returnOf, 'branch_id' => 1]);

        return $this->sales[] = (int) $db->insertID();
    }

    /**
     * @return array<string, array{key: string, kind: string, count: int, sample: list<int>}>
     */
    private function findings(): array
    {
        return array_column((new HistoryAudit())->run(), null, 'key');
    }

    public function testRun_FindsNothingWhileTheRulesWereNeverTurnedOn(): void
    {
        $this->sale('2026-11-21 10:00:00');

        $this->assertSame([], (new HistoryAudit())->run());
    }

    public function testRun_FindsTheSalesCompletedUnderTheRulesWithoutTheirDocumentOrTheirVat(): void
    {
        Database::connect()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $before   = $this->sale('2019-06-01 10:00:00');
        $under    = $this->sale('2026-11-21 10:00:00');
        $orphan   = $this->sale('2026-11-21 11:00:00', SALE_TYPE_RETURN);
        $findings = $this->findings();

        $this->assertContains($under, $findings['sales_without_document']['sample']);
        $this->assertNotContains($before, $findings['sales_without_document']['sample']);
        $this->assertContains($under, $findings['sales_without_vat_snapshot']['sample']);
        $this->assertSame([$orphan], $findings['returns_without_origin']['sample']);
        $this->assertContains($before, $findings['sales_before_the_rules']['sample']);
        $this->assertSame(HistoryAudit::NOTE, $findings['sales_before_the_rules']['kind']);
        $this->assertTrue(HistoryAudit::breached(array_values($findings)));
    }
}
