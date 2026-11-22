<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Tax\VatReturns;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\ItemFixtureTrait;

/**
 * The returns kept for each period: worked out again each time, approved only once the books are closed through the
 * period and nothing in the return is left unreviewed, never overlapping an approved one, and frozen once approved.
 * Each test reads a period of its own, far from the sales of the other tests.
 */
class VatReturnsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ItemFixtureTrait;

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

        $db->table('vat_returns')->truncate();
        $db->table('accounting_closed_periods')->where('note', 'vat return test')->delete();

        if ($this->sales !== []) {
            $db->table('sales_items')->whereIn('sale_id', $this->sales)->delete();
            $db->table('sales')->whereIn('sale_id', $this->sales)->delete();
        }

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingError
    {
        try {
            $action();
        } catch (AccountingException $refusal) {
            return $refusal->error;
        }

        $this->fail('The return was not refused.');
    }

    private function closeThrough(string $day): void
    {
        Database::connect()->table('accounting_closed_periods')->insert(['date_from' => '2031-01-01', 'date_to' => $day, 'note' => 'vat return test', 'company_id' => 1]);
    }

    /**
     * A sale saved before the Saudi rules whose line carried no tax: nothing tells what kind of supply it was.
     */
    private function untaxedSale(string $time, string $price): void
    {
        $db = Database::connect();

        $db->table('sales')->insert(['sale_time' => $time, 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_INVOICE, 'branch_id' => 1]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $this->createTestItem(HAS_NO_STOCK), 'line' => 0, 'quantity_purchased' => '1', 'item_cost_price' => '0', 'item_unit_price' => $price, 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'print_option' => 0]);

        $this->sales[] = $saleId;
    }

    public function testSaveDraft_KeepsOneDraftForAPeriodAndWorksItOutAgainEachTime(): void
    {
        $returns = new VatReturns();
        $first   = $returns->saveDraft('2031-02-01', '2031-02-28', [], 1);
        $again   = $returns->saveDraft('2031-02-01', '2031-02-28', ['15' => ['vat' => '12.00']], 1, ['note' => 'checked']);
        $row     = $returns->find($again);

        $this->assertSame($first, $again);
        $this->assertSame('12.00', json_decode((string) $row->boxes, true)['15']['vat']);
        $this->assertSame(['boxes' => ['15' => ['vat' => '12.00']], 'review' => ['note' => 'checked']], json_decode((string) $row->manual, true), 'what was typed is kept, for approving to work the return out again');
        $this->assertSame(VatReturns::DRAFT, $row->status);
    }

    public function testSaveDraft_RefusesAPeriodThatEndsBeforeItBeginsOrIsNotADay(): void
    {
        $returns = new VatReturns();

        $this->assertSame(AccountingError::VatReturnPeriodInvalid, $this->refusal(fn () => $returns->saveDraft('2031-02-28', '2031-02-01', [], 1)));
        $this->assertSame(AccountingError::VatReturnPeriodInvalid, $this->refusal(fn () => $returns->saveDraft('2031-02-01', '2031-02-30', [], 1)));
    }

    public function testApprove_RequiresTheBooksClosedThroughThePeriodAndThenFreezesTheReturn(): void
    {
        $returns  = new VatReturns();
        $returnId = $returns->saveDraft('2031-03-01', '2031-03-31', [], 1);

        $this->assertSame(AccountingError::VatReturnPeriodOpen, $this->refusal(fn () => $returns->approve($returnId, 1)));

        $this->closeThrough('2031-03-31');
        $returns->approve($returnId, 1);

        $this->assertSame(VatReturns::APPROVED, $returns->find($returnId)->status);
        $this->assertSame(AccountingError::VatReturnApproved, $this->refusal(fn () => $returns->saveDraft('2031-03-01', '2031-03-31', [], 1)));
        $this->assertSame(AccountingError::VatReturnApproved, $this->refusal(fn () => $returns->approve($returnId, 1)));
    }

    public function testSaveDraft_RefusesAPeriodThatOverlapsAnApprovedReturn(): void
    {
        $returns = new VatReturns();

        $this->closeThrough('2031-05-31');
        $returns->approve($returns->saveDraft('2031-05-01', '2031-05-31', [], 1), 1);

        $this->assertSame(AccountingError::VatReturnOverlaps, $this->refusal(fn () => $returns->saveDraft('2031-05-15', '2031-06-15', [], 1)), 'the days of May would be counted twice');
        $this->assertGreaterThan(0, $returns->saveDraft('2031-06-01', '2031-06-30', [], 1), 'the next period begins the day after');
    }

    public function testApprove_RefusesWhileTheSalesWithoutTaxSavedBeforeTheRulesAreNotSharedOut(): void
    {
        $returns = new VatReturns();

        $this->untaxedSale('2031-04-10 10:00:00', '250.00');
        $this->closeThrough('2031-04-30');

        $returnId = $returns->saveDraft('2031-04-01', '2031-04-30', [], 1);
        $row      = $returns->find($returnId);

        $this->assertSame('250.00', (string) $row->estimated, 'counted as not classified');
        $this->assertSame('0.00', json_decode((string) $row->boxes, true)['3']['amount'], 'and put in no box');
        $this->assertSame(AccountingError::VatReturnUnreviewed, $this->refusal(fn () => $returns->approve($returnId, 1)));

        $returns->saveDraft('2031-04-01', '2031-04-30', [], 1, ['zero' => '200', 'exports' => '50', 'note' => 'Read from the copies of the invoices']);
        $returns->approve($returnId, 1);

        $boxes = json_decode((string) $returns->find($returnId)->boxes, true);

        $this->assertSame(['200.00', '50.00', '250.00'], [$boxes['3']['amount'], $boxes['4']['amount'], $boxes['6']['amount']]);
        $this->assertSame(VatReturns::APPROVED, $returns->find($returnId)->status);
    }
}
