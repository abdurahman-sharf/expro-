<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReturnOverride;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The record of a return that a manager let through with no sale to take it back from: who may give
 * the reason, that it is written once with an audit entry, and the mark it puts on a journal.
 */
class ReturnOverrideTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ReturnOverride $override;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->override = new ReturnOverride();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function aReturn(): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '-1', '10.00')], [], [], SALE_TYPE_RETURN);
    }

    public function testReasonFor_RequiresThePermissionOfTheEmployee(): void
    {
        $holder   = $this->employeeWithGrants(['sales_return_override']);
        $outsider = $this->employeeWithGrants([]);

        $this->assertSame('lost the receipt', $this->override->reasonFor($holder, '  lost the receipt '));

        try {
            $this->override->reasonFor($outsider, 'lost the receipt');
            $this->fail('An employee without the permission was let through.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        }
    }

    public function testReasonFor_RequiresAReasonOfOneToTwoHundredAndFiftyFiveCharacters(): void
    {
        $holder = $this->employeeWithGrants(['sales_return_override']);

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            try {
                $this->override->reasonFor($holder, $reason);
                $this->fail('The reason was accepted.');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::ReasonRequired, $refusal->error);
            }
        }

        $this->assertSame(str_repeat('x', 255), $this->override->reasonFor($holder, str_repeat('x', 255)));
    }

    public function testReasonFor_LetsTheBooksThroughWithoutAnEmployee(): void
    {
        $this->assertSame('migration', $this->override->reasonFor(null, 'migration'));
    }

    public function testRecord_WritesTheOverrideOnceWithItsAuditEntry(): void
    {
        $saleId = $this->aReturn();

        $this->assertFalse($this->override->exists($saleId));

        $this->override->record($saleId, 1, $this->employeeId, 'lost the receipt');
        $this->override->record($saleId, 1, $this->employeeId, 'another reason');

        $this->assertTrue($this->override->exists($saleId));
        $this->assertSame(1, $this->countRows('sale_return_overrides'));
        $this->assertSame('lost the receipt', $this->accountingDb()->table('sale_return_overrides')->where('sale_id', $saleId)->get()->getRow()->reason);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => ReturnOverride::AUDIT_ACTION])->countAllResults());
    }

    public function testRecord_RefusesASaleThatDoesNotExistAndAnEmptyReason(): void
    {
        $saleId = $this->aReturn();

        foreach ([[999999, 'reason'], [$saleId, '']] as [$id, $reason]) {
            try {
                $this->override->record($id, 1, $this->employeeId, $reason);
                $this->fail('The record was accepted.');
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->countRows('sale_return_overrides'));
    }

    public function testDescribe_AddsTheMarkOnlyToAnOverriddenReturn(): void
    {
        $this->assertSame('Return #5 ' . ReturnOverride::MARKER, ReturnOverride::describe('Return #5', true));
        $this->assertSame('Return #5', ReturnOverride::describe('Return #5', false));
    }
}
