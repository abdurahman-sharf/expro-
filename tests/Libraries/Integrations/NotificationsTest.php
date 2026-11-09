<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\NotificationRules;
use App\Libraries\Integrations\Notifications;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * What an employee sees of the notifications: the open ones of the rules that are on, in the branches the
 * employee works in, what each says, and the reading of each kept for the employee who read it.
 */
class NotificationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $ruleId;
    private Notifications $notifications;
    private string $locale;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->locale        = service('language')->getLocale();
        $this->ruleId        = (new NotificationRules())->create('low_stock', 'Low stock', null, null);
        $this->notifications = new Notifications();
    }

    protected function tearDown(): void
    {
        service('language')->setLocale($this->locale);
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $params
     */
    private function notice(string $subject, ?int $branchId, array $params = [], ?int $ruleId = null, bool $closed = false): int
    {
        $db = Database::connect();

        $db->table('notifications')->insert([
            'rule_id'     => $ruleId ?? $this->ruleId,
            'subject_key' => $subject,
            'open_key'    => $closed ? null : $subject,
            'branch_id'   => $branchId,
            'params'      => json_encode($params + ['item' => 'Pen', 'location' => 'Shop', 'quantity' => '2', 'reorder' => '5']),
            'resolved_at' => $closed ? '2027-01-01 00:00:00' : null,
        ]);

        return (int) $db->insertID();
    }

    /**
     * @return list<int>
     */
    private function idsSeenBy(int $personId, bool $unreadOnly = false): array
    {
        return array_map(static fn (object $row): int => (int) $row->notification_id, $this->notifications->visible($personId, $unreadOnly, 50, 0)['rows']);
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

    public function testVisible_ListsTheOpenNotificationsNewestFirstWithTheirKindAndBranch(): void
    {
        $first  = $this->notice('stock:1:1', 1);
        $second = $this->notice('stock:2:1', 1);
        $viewer = $this->employeeWithGrants([IntegrationAccess::NOTIFICATIONS_MODULE]);

        $found = $this->notifications->visible($viewer, false, 50, 0);

        $this->assertSame(2, $found['total']);
        $this->assertSame(2, $found['unread']);
        $this->assertSame([$second, $first], $this->idsSeenBy($viewer));
        $this->assertSame('low_stock', $found['rows'][0]->kind);
        $this->assertSame('Low stock', $found['rows'][0]->rule_name);
        $this->assertSame('1', (string) $found['rows'][0]->branch_id);
        $this->assertSame(0, (int) $found['rows'][0]->is_read);
    }

    public function testVisible_LeavesOutClosedNotificationsAndThoseOfARuleThatIsSwitchedOff(): void
    {
        $open   = $this->notice('stock:1:1', 1);
        $closed = $this->notice('stock:2:1', 1, [], null, true);
        $off    = (new NotificationRules())->create('cheque_due', 'Cheques', '3', null);
        $hidden = $this->notice('cheque:1', 1, [], $off);
        $viewer = $this->employeeWithGrants([]);

        (new NotificationRules())->setActive($off, false, null);

        $this->assertSame([$open], $this->idsSeenBy($viewer));
        $this->assertNotContains($closed, $this->idsSeenBy($viewer));
        $this->assertNotContains($hidden, $this->idsSeenBy($viewer));
    }

    public function testVisible_ShowsAnEmployeeOfOneBranchOnlyThatBranchAndNothingOfNoBranch(): void
    {
        $second = $this->createBranch('B2');
        $first  = $this->notice('stock:1:1', 1);
        $other  = $this->notice('stock:2:2', $second);
        $whole  = $this->notice('invoice:-1', null);

        $inFirst  = $this->employeeWithGrants([], [1]);
        $inSecond = $this->employeeWithGrants([], [$second]);
        $everyone = $this->employeeWithGrants([]);

        $this->assertSame([$first], $this->idsSeenBy($inFirst));
        $this->assertSame([$other], $this->idsSeenBy($inSecond));
        $this->assertSame([$whole, $other, $first], $this->idsSeenBy($everyone));
    }

    public function testVisible_ShowsAnEmployeeWhoWorksNowhereNothing(): void
    {
        $this->notice('stock:1:1', 1);
        $nowhere = $this->employeeWithGrants([], []);

        $this->assertSame([], $this->idsSeenBy($nowhere));
        $this->assertSame(0, $this->notifications->unreadCount($nowhere));
    }

    public function testMarkRead_KeepsTheReadingForTheEmployeeWhoReadItAndNoOneElse(): void
    {
        $id    = $this->notice('stock:1:1', 1);
        $alice = $this->employeeWithGrants([]);
        $bob   = $this->employeeWithGrants([]);

        $this->notifications->markRead($id, $alice);
        $this->notifications->markRead($id, $alice);

        $this->assertSame(0, $this->notifications->unreadCount($alice));
        $this->assertSame(1, $this->notifications->unreadCount($bob));
        $this->assertSame([], $this->idsSeenBy($alice, true));
        $this->assertSame([$id], $this->idsSeenBy($alice), 'A read notification is still listed.');
        $this->assertSame(1, (int) $this->notifications->visible($alice, false, 50, 0)['rows'][0]->is_read);
        $this->assertSame(1, Database::connect()->table('notification_reads')->countAllResults());
    }

    public function testMarkRead_RefusesANotificationTheEmployeeDoesNotSeeOrThatDoesNotExist(): void
    {
        $second   = $this->createBranch('B2');
        $other    = $this->notice('stock:2:2', $second);
        $inFirst  = $this->employeeWithGrants([], [1]);

        $this->assertSame(IntegrationError::NotificationNotFound, $this->refusal(fn () => $this->notifications->markRead($other, $inFirst))->error);
        $this->assertSame(IntegrationError::NotificationNotFound, $this->refusal(fn () => $this->notifications->markRead(987654, $inFirst))->error);
        $this->assertSame(0, Database::connect()->table('notification_reads')->countAllResults());
    }

    public function testMarkRead_RefusesAClosedNotification(): void
    {
        $closed = $this->notice('stock:1:1', 1, [], null, true);

        $this->assertSame(IntegrationError::NotificationNotFound, $this->refusal(fn () => $this->notifications->markRead($closed, $this->employeeWithGrants([])))->error);
    }

    public function testMarkAllRead_MarksOnlyWhatTheEmployeeSeesAndHasNotRead(): void
    {
        $second = $this->createBranch('B2');
        $one    = $this->notice('stock:1:1', 1);
        $two    = $this->notice('stock:2:1', 1);
        $other  = $this->notice('stock:3:2', $second);
        $viewer = $this->employeeWithGrants([], [1]);

        $this->notifications->markRead($one, $viewer);

        $this->assertSame(1, $this->notifications->markAllRead($viewer));
        $this->assertSame(0, $this->notifications->markAllRead($viewer));
        $this->assertSame(0, $this->notifications->unreadCount($viewer));
        $this->assertSame(0, Database::connect()->table('notification_reads')->where(['notification_id' => $other])->countAllResults());
        $this->assertSame(2, Database::connect()->table('notification_reads')->whereIn('notification_id', [$one, $two])->countAllResults());
    }

    public function testVisible_ListsOnlyTheUnreadOnesWhenAsked(): void
    {
        $read   = $this->notice('stock:1:1', 1);
        $unread = $this->notice('stock:2:1', 1);
        $viewer = $this->employeeWithGrants([]);

        $this->notifications->markRead($read, $viewer);

        $found = $this->notifications->visible($viewer, true, 50, 0);

        $this->assertSame([$unread], $this->idsSeenBy($viewer, true));
        $this->assertSame(1, $found['total']);
        $this->assertSame(1, $found['unread']);
    }

    public function testVisible_PagesTheListAndKeepsTheTotal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->notice("stock:{$i}:1", 1);
        }

        $viewer = $this->employeeWithGrants([]);
        $page   = $this->notifications->visible($viewer, false, 2, 4);

        $this->assertSame(5, $page['total']);
        $this->assertCount(1, $page['rows']);
    }

    public function testMessage_SaysWhatTheNotificationIsAboutInTheLanguageInUse(): void
    {
        service('language')->setLocale('en');

        $low = (object) ['kind' => 'low_stock', 'rule_name' => 'Low stock', 'params' => json_encode(['item' => 'Pen', 'location' => 'Shop', 'quantity' => '2', 'reorder' => '5'])];

        $this->assertSame('Pen is down to 2 in Shop (reorder level 5).', Notifications::message($low));

        $overdue = (object) ['kind' => 'invoice_overdue', 'rule_name' => 'Late', 'params' => json_encode(['customer' => 'Omar', 'document' => 'INV-7', 'kind' => 'invoice', 'amount' => '100.0000', 'days' => '40'])];

        $this->assertSame('Invoice INV-7 of Omar is overdue by 40 days: 100.0000 is still owed.', Notifications::message($overdue));

        $cheque = (object) ['kind' => 'cheque_due', 'rule_name' => 'Cheques', 'params' => json_encode(['number' => 'CH-1', 'party' => 'Omar', 'amount' => '250.0000', 'due' => '2027-03-01', 'direction' => 'received'])];

        $this->assertSame('Cheque CH-1 of Omar for 250.0000 falls due on 2027-03-01.', Notifications::message($cheque));
    }

    public function testMessage_NamesAnOpeningBalanceAndADebitNoteWhereThereIsNoInvoiceNumber(): void
    {
        $opening = (object) ['kind' => 'invoice_overdue', 'rule_name' => 'Late', 'params' => json_encode(['customer' => 'Omar', 'document' => '', 'kind' => 'opening', 'amount' => '5.0000', 'days' => '3'])];
        $note    = (object) ['kind' => 'invoice_overdue', 'rule_name' => 'Late', 'params' => json_encode(['customer' => 'Omar', 'document' => '', 'kind' => 'debit_note', 'amount' => '5.0000', 'days' => '3'])];

        $this->assertStringContainsString(lang('Integrations.document_opening'), Notifications::message($opening));
        $this->assertStringContainsString(lang('Integrations.document_debit_note'), Notifications::message($note));
    }

    public function testMessage_FallsBackToTheNameOfTheRuleForAKindItDoesNotKnow(): void
    {
        $this->assertSame('Old rule', Notifications::message((object) ['kind' => 'retired', 'rule_name' => 'Old rule', 'params' => '{}']));
    }
}
