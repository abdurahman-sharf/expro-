<?php

namespace Tests\Database;

use App\Database\Migrations\PostingRulesSale;
use App\Libraries\Accounting\Engine\PostingRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesSaleMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const OPERATIONS = ['sale', 'sale_cost'];

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
        (new PostingRulesSale())->up();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfASaleAndOfItsCostAsTheyArePostedAndPutsEachInShadowOnce(): void
    {
        $db = $this->accountingDb();
        (new PostingRulesSale())->down();
        (new PostingRulesSale())->up();
        (new PostingRulesSale())->up();

        $shapes = [];

        foreach (self::OPERATIONS as $operation) {
            $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => $operation])->countAllResults(), $operation);
            $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => $operation])->get()->getRow()->mode, $operation);

            $set                = (new PostingRules())->current($operation, '2026-10-21');
            $shapes[$operation] = [$set->sourceType, $set->foreignRounding, array_map(static fn ($line): array => [$line->side, $line->amount, $line->repeatOver, $line->foreignAmount], $set->lines)];
        }

        $this->assertSame([
            'sale'      => ['sale', ['balancing' => 'largest_of', 'lines' => [3], 'else' => [5]], [['debit', 'total', null, null], ['debit', 'discount', null, null], ['credit', 'value', 'goods', null], ['credit', 'value', 'taxes', null], ['credit', 'shipping', null, null], ['credit', 'adjustment', null, null], ['credit', 'rounding', null, null]]],
            'sale_cost' => ['sale', null, [['debit', 'cost', null, null], ['credit', 'value', 'locations', null]]],
        ], $shapes);
    }

    public function testDown_RemovesOnlyTheRulesAndTheModesOfASaleAndOfItsCost(): void
    {
        (new PostingRulesSale())->down();

        $db = $this->accountingDb();

        $this->assertSame(0, $db->table('accounting_posting_rule_sets')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(0, $db->table('accounting_posting_modes')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where('operation', 'purchase')->countAllResults());
    }
}
