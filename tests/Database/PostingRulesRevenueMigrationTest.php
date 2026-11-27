<?php

namespace Tests\Database;

use App\Database\Migrations\PostingRulesRevenue;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesRevenueMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
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
        (new PostingRulesRevenue())->up();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfTheReceiptVoucherAsItIsPostedAndPutsItInShadowOnce(): void
    {
        $db = $this->accountingDb();
        (new PostingRulesRevenue())->down();
        (new PostingRulesRevenue())->up();
        (new PostingRulesRevenue())->up();

        $sets = $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => 'revenue'])->get()->getResult();

        $this->assertCount(1, $sets);
        $this->assertSame(['1', 'revenue', '0'], [(string) $sets[0]->version, $sets[0]->source_type, (string) $sets[0]->cross_branch]);
        $this->assertSame(
            [['debit', 'gross'], ['credit', 'net'], ['credit', 'tax']],
            array_map(static fn (object $line): array => [$line->side, $line->amount], $db->table('accounting_posting_rules')->where('rule_set_id', $sets[0]->rule_set_id)->orderBy('line_no')->get()->getResult()),
        );
        $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => 'revenue'])->get()->getRow()->mode);
    }

    public function testDown_RemovesOnlyTheRulesAndTheModeOfTheReceiptVoucher(): void
    {
        (new PostingRulesRevenue())->down();

        $db = $this->accountingDb();

        $this->assertSame(0, $db->table('accounting_posting_rule_sets')->where('operation', 'revenue')->countAllResults());
        $this->assertSame(0, $db->table('accounting_posting_modes')->where('operation', 'revenue')->countAllResults());
        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where('operation', 'expense')->countAllResults());
    }
}
