<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\PurchaseVat;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class PurchaseVatTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var list<int>
     */
    private array $receivings = [];

    /**
     * @var array<string, mixed>
     */
    private array $profile = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Database::connect()->table('company_tax_profile')->get()->getRowArray();
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('company_tax_profile')->update(['ksa_mode' => $this->profile['ksa_mode'], 'ksa_mode_effective_at' => $this->profile['ksa_mode_effective_at']]);

        if ($this->receivings !== []) {
            $db->table('receivings')->whereIn('receiving_id', $this->receivings)->delete();
        }

        parent::tearDown();
    }

    private function receiving(?int $version): int
    {
        $db = Database::connect();
        $db->table('receivings')->insert(['receiving_time' => '2026-11-21 10:00:00', 'employee_id' => 1, 'comment' => '', 'reference' => '', 'vat_calc_version' => $version]);

        return $this->receivings[] = (int) $db->insertID();
    }

    public function testColumnsForNew_MarksAPurchaseOnlyWhileTheSaudiRulesApply(): void
    {
        $this->assertSame([], (new PurchaseVat())->columnsForNew());

        Database::connect()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $this->assertSame(['vat_calc_version' => PurchaseVat::VERSION], (new PurchaseVat())->columnsForNew());
    }

    public function testMarkedAmong_ListsThePurchasesThatCarryTheMark(): void
    {
        $marked   = $this->receiving(1);
        $unmarked = $this->receiving(null);

        $this->assertSame([$marked => true], (new PurchaseVat())->markedAmong([$marked, $unmarked]));
        $this->assertSame([], (new PurchaseVat())->markedAmong([]));
    }
}
