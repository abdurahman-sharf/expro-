<?php

namespace Tests\Database;

use App\Database\Migrations\PostingEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingEngineMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['accounting_posting_rule_sets', 'accounting_posting_rules', 'accounting_posting_modes', 'accounting_posting_shadow_diffs'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        (new PostingEngine())->up();

        foreach (['accounting_posting_modes', 'accounting_posting_rule_sets'] as $table) {
            $db->table($table)->where('company_id', 2)->delete();
        }

        $db->table('companies')->where('company_id', 2)->delete();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfTheExpenseAsItIsPostedAndPutsItInShadowForEveryCompany(): void
    {
        $db = $this->accountingDb();
        (new PostingEngine())->down();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);
        $journals = $this->countRows('accounting_journals');

        (new PostingEngine())->up();

        foreach ([1, 2] as $company) {
            $set = $db->table('accounting_posting_rule_sets')->where(['company_id' => $company, 'operation' => 'expense'])->get()->getRow();

            $this->assertSame(['1', '1900-01-01', 'expense', '1'], [(string) $set->version, $set->effective_from, $set->source_type, (string) $set->cross_branch], "company {$company}");
            $this->assertSame(
                [['credit', 'gross', 'P-45'], ['debit', 'net', 'P-45'], ['debit', 'tax', 'P-46']],
                array_map(static fn (object $line): array => [$line->side, $line->amount, $line->matrix_ref], $db->table('accounting_posting_rules')->where('rule_set_id', $set->rule_set_id)->orderBy('line_no')->get()->getResult()),
            );
            $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => $company, 'operation' => 'expense'])->get()->getRow()->mode);
        }

        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testUp_AddsNothingWhenRunAgainAndKeepsAModeThatWasChanged(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => 'expense'])->update(['mode' => 'engine']);

        (new PostingEngine())->up();

        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => 'expense'])->countAllResults());
        $this->assertSame('engine', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => 'expense'])->get()->getRow()->mode);

        $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => 'expense'])->update(['mode' => 'shadow']);
    }

    public function testDown_RemovesTheTablesOfTheEngineAndNothingElse(): void
    {
        $journals = $this->countRows('accounting_journals');

        (new PostingEngine())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->accountingDb()->tableExists($table, false), $table);
        }

        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }
}
