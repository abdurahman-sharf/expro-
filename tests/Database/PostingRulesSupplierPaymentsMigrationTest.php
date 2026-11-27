<?php

namespace Tests\Database;

use App\Database\Migrations\PostingRulesSupplierPayments;
use App\Libraries\Accounting\Engine\PostingRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesSupplierPaymentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const OPERATIONS = ['purchase_payment', 'supplier_payment', 'supplier_payment_fx', 'supplier_advance'];

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
        (new PostingRulesSupplierPayments())->up();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfEveryPaymentToASupplierAsItIsPostedAndPutsEachInShadowOnce(): void
    {
        $db = $this->accountingDb();
        (new PostingRulesSupplierPayments())->down();
        (new PostingRulesSupplierPayments())->up();
        (new PostingRulesSupplierPayments())->up();

        $shapes = [];

        foreach (self::OPERATIONS as $operation) {
            $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => $operation])->countAllResults(), $operation);
            $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => $operation])->get()->getRow()->mode, $operation);

            $set                = (new PostingRules())->current($operation, '2026-10-21');
            $shapes[$operation] = [$set->sourceType, $set->crossBranch, array_map(static fn ($line): array => [$line->side, $line->amount, $line->foreignAmount], $set->lines)];
        }

        $this->assertSame([
            'purchase_payment'    => ['purchase_payment', false, [['credit', 'paid', 'amount'], ['debit', 'paid', 'amount']]],
            'supplier_payment'    => ['supplier_payment', true, [['credit', 'paid', 'amount'], ['debit', 'paid', 'amount']]],
            'supplier_payment_fx' => ['supplier_payment_fx', false, [['debit', 'fx_diff', null], ['credit', 'fx_diff', null]]],
            'supplier_advance'    => ['supplier_advance', false, [['credit', 'amount', null], ['debit', 'amount', null]]],
        ], $shapes);
    }

    public function testDown_RemovesOnlyTheRulesAndTheModesOfThePaymentsToASupplier(): void
    {
        (new PostingRulesSupplierPayments())->down();

        $db = $this->accountingDb();

        $this->assertSame(0, $db->table('accounting_posting_rule_sets')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(0, $db->table('accounting_posting_modes')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where('operation', 'sale_payment')->countAllResults());
    }
}
