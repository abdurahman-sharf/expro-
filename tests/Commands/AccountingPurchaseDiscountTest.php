<?php

namespace Tests\Commands;

use App\Libraries\Accounting\LegacyPurchaseDiscount;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class AccountingPurchaseDiscountTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var list<int>
     */
    private array $receivingIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new LegacyPurchaseDiscount())->clearResolution();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->receivingIds as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->receivingIds = [];

        (new LegacyPurchaseDiscount())->clearResolution();

        parent::tearDown();
    }

    private function savedAs(string $savedAt): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeWithGrants([]), 'comment' => '', 'receiving_time' => $savedAt]);

        $receivingId          = (int) $db->insertID();
        $this->receivingIds[] = $receivingId;

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->createTestItem(HAS_STOCK),
            'line'               => 1,
            'quantity_purchased' => '10',
            'receiving_quantity' => '1',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '50.00',
            'discount'           => '20.00',
            'discount_type'      => FIXED,
            'item_location'      => 1,
        ]);

        return $receivingId;
    }

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command(trim('accounting:purchase_discount ' . $arguments));

        return $this->getStreamFilterBuffer();
    }

    public function testRun_SaysThereIsNothingToMarkWhenNoLineCarriesAFixedDiscountOverSeveralUnits(): void
    {
        $this->assertStringContainsString('nothing to mark', $this->runCommand());
    }

    public function testRun_CountsTheLinesByWhatDecidesThemAndListsTheOnesNothingDecides(): void
    {
        $early = $this->savedAs('2026-10-01 09:00:00');
        $late  = $this->savedAs(date('Y-m-d H:i:s'));

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/legacy_by_date\s+1/', $output);
        $this->assertMatchesRegularExpression('/undetermined\s+1/', $output);
        $this->assertStringContainsString("purchase #{$late} line 1", $output);
        $this->assertStringNotContainsString("purchase #{$early} line 1", $output);
        $this->assertStringContainsString('stop on 1', $output);
        $this->assertStringContainsString('Latest saved for the whole line, by the books: none', $output);
        $this->assertStringContainsString('Earliest saved for every unit, by the books: none', $output);
        $this->assertStringContainsString('will stop until the lines above are decided', $output);
    }

    public function testRun_ChangesNoPurchase(): void
    {
        $early = $this->savedAs('2026-10-01 09:00:00');

        $this->runCommand();
        $this->runCommand('resolve --all-legacy');

        $this->assertSame(FIXED, (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $early)->get()->getRow()->discount_type);
    }

    public function testRun_RecordsTheDecisionAndSaysTheMigrationWillNoLongerStop(): void
    {
        $this->savedAs(date('Y-m-d H:i:s'));

        $output = $this->runCommand('resolve --none-legacy');

        $this->assertStringContainsString('every undecided line is for every unit', $output);
        $this->assertStringContainsString('stop on 0', $output);
        $this->assertSame(['kind' => LegacyPurchaseDiscount::NONE, 'before' => null], (new LegacyPurchaseDiscount())->resolution());
    }

    public function testRun_RecordsAMomentAndRefusesOneThatIsNotADate(): void
    {
        $this->assertStringContainsString('YYYY-MM-DD HH:MM:SS', $this->runCommand('resolve --before "2026-10-26"'));
        $this->assertNull((new LegacyPurchaseDiscount())->resolution());

        $this->runCommand('resolve --before "2026-10-26 12:00:00"');

        $this->assertSame(['kind' => LegacyPurchaseDiscount::BEFORE, 'before' => '2026-10-26 12:00:00'], (new LegacyPurchaseDiscount())->resolution());
    }

    public function testRun_RequiresExactlyOneDecisionToResolve(): void
    {
        $this->assertStringContainsString('exactly one of', $this->runCommand('resolve'));
        $this->assertStringContainsString('exactly one of', $this->runCommand('resolve --all-legacy --none-legacy'));
        $this->assertNull((new LegacyPurchaseDiscount())->resolution());
    }

    public function testRun_ForgetsTheRecordedDecision(): void
    {
        $this->runCommand('resolve --all-legacy');
        $this->runCommand('resolve --clear');

        $this->assertNull((new LegacyPurchaseDiscount())->resolution());
    }

    public function testRun_RefusesAnActionItDoesNotKnow(): void
    {
        $this->assertStringContainsString('must be scan or resolve', $this->runCommand('apply'));
    }
}
