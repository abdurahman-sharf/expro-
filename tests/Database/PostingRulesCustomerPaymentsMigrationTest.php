<?php

namespace Tests\Database;

use App\Database\Migrations\PostingRulesCustomerPayments;
use App\Libraries\Accounting\Engine\PostingRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingRulesCustomerPaymentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const OPERATIONS = ['sale_payment', 'sale_payment_fx', 'customer_advance'];

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
        (new PostingRulesCustomerPayments())->up();

        parent::tearDown();
    }

    public function testUp_SeedsTheRulesOfEveryPaymentOfACustomerAsItIsPostedAndPutsEachInShadowOnce(): void
    {
        $db = $this->accountingDb();
        (new PostingRulesCustomerPayments())->down();
        (new PostingRulesCustomerPayments())->up();
        (new PostingRulesCustomerPayments())->up();

        $shapes = [];

        foreach (self::OPERATIONS as $operation) {
            $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where(['company_id' => 1, 'operation' => $operation])->countAllResults(), $operation);
            $this->assertSame('shadow', $db->table('accounting_posting_modes')->where(['company_id' => 1, 'operation' => $operation])->get()->getRow()->mode, $operation);

            $set                = (new PostingRules())->current($operation, '2026-10-21');
            $shapes[$operation] = [$set->sourceType, $set->crossBranch, array_map(static fn ($line): array => [$line->side, $line->amount, $line->foreignAmount], $set->lines)];
        }

        $this->assertSame([
            'sale_payment'     => ['sale_payment', true, [['credit', 'adjustment + paid', 'amount'], ['debit', 'adjustment', null], ['debit', 'paid', 'amount']]],
            'sale_payment_fx'  => ['sale_payment_fx', false, [['credit', 'fx_diff', null], ['debit', 'fx_diff', null]]],
            'customer_advance' => ['customer_advance', false, [['debit', 'amount', null], ['credit', 'amount', null]]],
        ], $shapes);
    }

    public function testDown_RemovesOnlyTheRulesAndTheModesOfThePaymentsOfACustomer(): void
    {
        (new PostingRulesCustomerPayments())->down();

        $db = $this->accountingDb();

        $this->assertSame(0, $db->table('accounting_posting_rule_sets')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(0, $db->table('accounting_posting_modes')->whereIn('operation', self::OPERATIONS)->countAllResults());
        $this->assertSame(1, $db->table('accounting_posting_rule_sets')->where('operation', 'revenue')->countAllResults());
    }
}
