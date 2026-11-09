<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\NotificationRuleKind;
use App\Libraries\Integrations\NotificationRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The rules of in-app notifications: what a rule is, the days each kind takes, that a rule that has made
 * notifications is switched off and kept, who may change them, and that every change is audited.
 */
class NotificationRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private NotificationRules $rules;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->rules = new NotificationRules();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusal(callable $action): IntegrationException
    {
        try {
            $action();
        } catch (IntegrationException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testCreate_MakesAnActiveRuleWithItsDaysAndAuditsIt(): void
    {
        $manager = $this->integrationManager();

        $id = $this->rules->create('invoice_overdue', '  Late   invoices ', '7', $manager);

        $rule = $this->rules->find($id);

        $this->assertSame(NotificationRuleKind::InvoiceOverdue, $rule->kind);
        $this->assertSame('Late invoices', $rule->name);
        $this->assertSame(7, $rule->threshold);
        $this->assertTrue($rule->active);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'notification_rule_created')->get()->getRow();

        $this->assertSame($id, (int) $entry->entity_id);
        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(['kind' => 'invoice_overdue', 'name' => 'Late invoices', 'threshold' => 7], json_decode($entry->details, true));
    }

    public function testCreate_KeepsNoDaysForLowStock(): void
    {
        $id = $this->rules->create('low_stock', 'Low', '99', null);

        $this->assertNull($this->rules->find($id)->threshold);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function daysOfEachKind(): array
    {
        return [
            'overdue: none'            => ['invoice_overdue', '0', false],
            'overdue: one'             => ['invoice_overdue', '1', true],
            'overdue: a year'          => ['invoice_overdue', '365', true],
            'overdue: past a year'     => ['invoice_overdue', '366', false],
            'overdue: not a number'    => ['invoice_overdue', 'week', false],
            'overdue: a fraction'      => ['invoice_overdue', '1.5', false],
            'cheque: the day itself'   => ['cheque_due', '0', true],
            'cheque: ninety days'      => ['cheque_due', '90', true],
            'cheque: past ninety days' => ['cheque_due', '91', false],
            'cheque: before the day'   => ['cheque_due', '-1', false],
            'cheque: empty'            => ['cheque_due', '', false],
        ];
    }

    #[DataProvider('daysOfEachKind')]
    public function testCreate_TakesTheDaysOfItsKindWithinTheirBoundsAndNoOthers(string $kind, string $days, bool $accepted): void
    {
        if ($accepted) {
            $this->assertGreaterThan(0, $this->rules->create($kind, 'Rule', $days, null));

            return;
        }

        $this->assertSame(IntegrationError::RuleThreshold, $this->refusal(fn () => $this->rules->create($kind, 'Rule', $days, null))->error);
        $this->assertSame(0, Database::connect()->table('notification_rules')->countAllResults());
    }

    public function testCreate_RejectsAKindItDoesNotKnowAndABadName(): void
    {
        $this->assertSame(IntegrationError::RuleKind, $this->refusal(fn () => $this->rules->create('birthday', 'Rule', '1', null))->error);
        $this->assertSame(IntegrationError::RuleName, $this->refusal(fn () => $this->rules->create('low_stock', '', null, null))->error);
        $this->assertSame(IntegrationError::RuleName, $this->refusal(fn () => $this->rules->create('low_stock', str_repeat('n', 101), null, null))->error);
        $this->assertSame(IntegrationError::RuleName, $this->refusal(fn () => $this->rules->create('low_stock', "Ru\x07le", null, null))->error);
        $this->assertGreaterThan(0, $this->rules->create('low_stock', str_repeat('n', 100), null, null));
    }

    public function testCreate_RejectsANameAnotherRuleHasAndAcceptsItForTheSameRuleOnUpdate(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        $this->assertSame(IntegrationError::RuleNameTaken, $this->refusal(fn () => $this->rules->create('cheque_due', 'Low', '3', null))->error);

        $this->rules->update($id, 'Low', null, null);
        $this->rules->update($id, 'Low stock', null, null);

        $this->assertSame('Low stock', $this->rules->find($id)->name);
    }

    public function testCreate_RequiresThePermissionToManage(): void
    {
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->rules->create('low_stock', 'Low', null, $outsider))->error);
        $this->assertSame(0, Database::connect()->table('notification_rules')->countAllResults());
    }

    public function testUpdate_ChangesTheNameAndTheDaysAndAuditsWhatTheyWere(): void
    {
        $id = $this->rules->create('invoice_overdue', 'Late', '7', null);

        $this->rules->update($id, 'Very late', '30', $this->integrationManager());

        $this->assertSame(30, $this->rules->find($id)->threshold);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'notification_rule_updated')->get()->getRow();

        $this->assertSame(['before' => ['name' => 'Late', 'threshold' => 7], 'after' => ['name' => 'Very late', 'threshold' => 30]], json_decode($entry->details, true));
    }

    public function testUpdate_WritesAndAuditsNothingWhenNothingChanges(): void
    {
        $id = $this->rules->create('invoice_overdue', 'Late', '7', null);

        $this->rules->update($id, 'Late', '7', null);

        $this->assertSame(['notification_rule_created'], $this->integrationAudit('notification_rule'));
    }

    public function testUpdate_RejectsARuleThatDoesNotExistAndDaysOutsideTheBoundsOfItsKind(): void
    {
        $id = $this->rules->create('cheque_due', 'Cheques', '3', null);

        $this->assertSame(IntegrationError::RuleNotFound, $this->refusal(fn () => $this->rules->update(987654, 'x', '1', null))->error);
        $this->assertSame(IntegrationError::RuleThreshold, $this->refusal(fn () => $this->rules->update($id, 'Cheques', '91', null))->error);
        $this->assertSame(3, $this->rules->find($id)->threshold);
    }

    public function testUpdate_RequiresThePermissionToManage(): void
    {
        $id       = $this->rules->create('low_stock', 'Low', null, null);
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE]);

        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->rules->update($id, 'Changed', null, $outsider))->error);
        $this->assertSame('Low', $this->rules->find($id)->name);
    }

    public function testSetActive_SwitchesARuleOffAndOnAndAuditsEachChangeOnly(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        $this->rules->setActive($id, false, null);
        $this->rules->setActive($id, false, null);

        $this->assertFalse($this->rules->find($id)->active);

        $this->rules->setActive($id, true, null);

        $this->assertTrue($this->rules->find($id)->active);
        $this->assertSame(['notification_rule_created', 'notification_rule_deactivated', 'notification_rule_activated'], $this->integrationAudit('notification_rule'));
    }

    public function testSetActive_RejectsARuleThatDoesNotExistAndRequiresThePermission(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        $this->assertSame(IntegrationError::RuleNotFound, $this->refusal(fn () => $this->rules->setActive(987654, false, null))->error);
        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->rules->setActive($id, false, $this->employeeWithGrants([])))->error);
        $this->assertTrue($this->rules->find($id)->active);
    }

    public function testAll_ListsTheRulesInTheOrderTheyWereMadeAndCanListOnlyTheActiveOnes(): void
    {
        $first  = $this->rules->create('low_stock', 'A', null, null);
        $second = $this->rules->create('cheque_due', 'B', '2', null);

        $this->rules->setActive($first, false, null);

        $this->assertSame([$first, $second], array_map(static fn ($rule): int => $rule->id, $this->rules->all()));
        $this->assertSame([$second], array_map(static fn ($rule): int => $rule->id, $this->rules->all(true)));
    }

    public function testDelete_RemovesARuleThatMadeNothingAndAuditsIt(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        $this->rules->delete($id, $this->integrationManager());

        $this->assertNull($this->rules->find($id));
        $this->assertSame(['notification_rule_created', 'notification_rule_deleted'], $this->integrationAudit('notification_rule'));
    }

    public function testDelete_RefusesARuleThatMadeNotificationsEvenClosedOnes(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        Database::connect()->table('notifications')->insert(['rule_id' => $id, 'subject_key' => 'stock:1:1', 'open_key' => null, 'resolved_at' => '2027-01-01 00:00:00']);

        $this->assertSame(IntegrationError::RuleInUse, $this->refusal(fn () => $this->rules->delete($id, null))->error);
        $this->assertNotNull($this->rules->find($id));
    }

    public function testDelete_RejectsARuleThatDoesNotExistAndRequiresThePermission(): void
    {
        $id = $this->rules->create('low_stock', 'Low', null, null);

        $this->assertSame(IntegrationError::RuleNotFound, $this->refusal(fn () => $this->rules->delete(987654, null))->error);
        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->rules->delete($id, $this->employeeWithGrants([])))->error);
        $this->assertNotNull($this->rules->find($id));
    }
}
