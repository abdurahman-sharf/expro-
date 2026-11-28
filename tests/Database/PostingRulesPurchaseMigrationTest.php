<?php

namespace Tests\Database;

use App\Database\Migrations\PostingRulesPurchase;
use App\Libraries\Accounting\Engine\PostingRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesPurchaseMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const OPERATIONS = ['purchase', 'purchase_return'];

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
        (new PostingRulesPurchase())->up();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfAPurchaseAndOfAReturnAsTheyArePostedAndPutsEachInShadowOnce(): void
    {
        $db = $this->accountingDb();
        (new PostingRulesPurchase())->down();
        (new PostingRulesPurchase())->up();
        (new PostingRulesPurchase())->up();

        $shapes = [];

        foreach (self::OPERATIONS as $operation) {
            $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => $operation])->countAllResults(), $operation);
            $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => $operation])->get()->getRow()->mode, $operation);

            $set                = (new PostingRules())->current($operation, '2026-10-21');
            $shapes[$operation] = [$set->sourceType, $set->foreignRounding, array_map(static fn ($line): array => [$line->side, $line->amount, $line->repeatOver, $line->foreignAmount], $set->lines)];
        }

        $this->assertSame([
            'purchase'        => ['purchase', ['balancing' => 'largest_debit', 'except' => [3]], [['debit', 'value', 'locations', null], ['debit', 'net', null, null], ['debit', 'tax', null, null], ['credit', 'total', null, null]]],
            'purchase_return' => ['purchase', null, [['debit', 'total', null, 'foreign_total'], ['credit', 'tax', null, 'foreign_tax'], ['credit', 'value', 'locations', null], ['credit', 'net', null, null], ['credit', 'variance', null, null]]],
        ], $shapes);
    }

    public function testDown_RemovesOnlyTheRulesAndTheModesOfAPurchaseAndOfAReturn(): void
    {
        (new PostingRulesPurchase())->down();

        $db = $this->accountingDb();

        $this->assertSame(0, $db->table('accounting_posting_rule_sets')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(0, $db->table('accounting_posting_modes')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where('operation', 'supplier_payment')->countAllResults());
    }
}
