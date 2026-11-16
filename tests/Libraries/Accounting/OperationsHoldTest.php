<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\OperationsHold;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class OperationsHoldTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private OperationsHold $hold;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        (new EntityAccountService())->fill();
        $this->installFiscalYears();
        $this->accountingDb()->table('accounting_audit_log')->truncate();
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->setConfigValue(OperationsHold::KEY, '');

        $this->manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->hold    = new OperationsHold();
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(OperationsHold::KEY, '');
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the refusal ' . $expected->value . '.');
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->orderBy('audit_id')->get()->getResultArray(), 'action');
    }

    public function testEngage_FreezesOperationsAndRecordsWhoAndWhy(): void
    {
        $this->hold->engage('  Fixing the routing  ', $this->manager);

        $state = $this->hold->state();

        $this->assertTrue($this->hold->isOn());
        $this->assertSame([$this->manager, 'Fixing the routing'], [$state['by'], $state['reason']]);
        $this->assertSame(['operations_held'], $this->actions());
    }

    public function testEngage_RequiresThePermissionAndEveryBranch(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->hold->engage('Fixing', $this->employeeWithGrants([])));

        $branch = $this->createBranch();

        $this->expectException(BranchException::class);
        $this->hold->engage('Fixing', $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], [$branch]));
    }

    public function testEngage_RefusesWithoutAReasonOrWithAnOverlongOne(): void
    {
        $this->assertFailsWith(AccountingError::HoldReasonRequired, fn () => $this->hold->engage('   ', $this->manager));
        $this->assertFailsWith(AccountingError::HoldReasonRequired, fn () => $this->hold->engage(str_repeat('x', OperationsHold::REASON_MAX + 1), $this->manager));
        $this->assertFalse($this->hold->isOn());
    }

    public function testEngage_RefusesWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertFailsWith(AccountingError::HoldNeedsAccounting, fn () => $this->hold->engage('Fixing', $this->manager));
    }

    public function testEngage_RefusesWhenOperationsAreFrozenAlready(): void
    {
        $this->hold->engage('First', $this->manager);

        $this->assertFailsWith(AccountingError::HoldActive, fn () => $this->hold->engage('Second', $this->manager));
        $this->assertSame('First', $this->hold->state()['reason']);
    }

    public function testAssertOpen_DoesNothingWhileNothingIsFrozen(): void
    {
        $this->hold->assertOpen();

        $this->assertFalse($this->hold->isOn());
    }

    public function testAssertOpen_RefusesNewDocumentsWithTheReasonAndTheTime(): void
    {
        $this->hold->engage('Fixing the routing', $this->manager);

        try {
            $this->hold->assertOpen();
            $this->fail('Expected the freeze to refuse.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::OperationsHeld, $exception->error);
            $this->assertSame('Fixing the routing', $exception->context['name']);
            $this->assertNotEmpty($exception->context['date']);
        }
    }

    public function testRelease_LiftsTheFreezeAndRecordsTheReasonAndHowLongItLasted(): void
    {
        $this->hold->engage('Fixing the routing', $this->manager);
        $this->hold->release($this->manager);

        $this->assertFalse($this->hold->isOn());
        $this->assertSame(['operations_held', 'operations_released'], $this->actions());

        $details = json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where('action', 'operations_released')->get()->getRow()->details, true);

        $this->assertSame('Fixing the routing', $details['reason']);
        $this->assertArrayHasKey('minutes', $details);
    }

    public function testRelease_RefusesWhenOperationsAreNotFrozen(): void
    {
        $this->assertFailsWith(AccountingError::HoldInactive, fn () => $this->hold->release($this->manager));
    }

    public function testRelease_KeepsTheFreezeWhileSomethingStillStopsAPosting(): void
    {
        $this->hold->engage('Fixing the routing', $this->manager);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        try {
            $this->hold->release($this->manager);
            $this->fail('Expected the readiness to refuse.');
        } catch (AccountingNotReadyException) {
            $this->assertTrue($this->hold->isOn());
        } finally {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => $this->accountId(RoutingKey::from('sales.cogs')->defaultSystemKey())]);
        }
    }

    public function testRelease_RequiresThePermission(): void
    {
        $this->hold->engage('Fixing', $this->manager);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->hold->release($this->employeeWithGrants([])));
        $this->assertTrue($this->hold->isOn());
    }
}
