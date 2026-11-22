<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\NotificationEvaluator;
use App\Libraries\Integrations\NotificationRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the rules find and the one open notification each subject keeps: the three kinds of rule, a second
 * run that changes nothing, a notification closed when its subject no longer matches and raised again when
 * it comes back, the rules that are off, and that nothing in the namespace can send anything anywhere.
 */
class NotificationEvaluatorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private NotificationEvaluator $evaluator;
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
        $this->startSelling();
        $this->evaluator = new NotificationEvaluator();
        $this->rules     = new NotificationRules();
        $this->switchIntegrationOn(IntegrationOptions::NOTIFICATIONS);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('cheques')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('item_quantities')->where('location_id !=', self::LOCATION_ID)->delete();
        $this->wipeIntegration();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @return list<object>
     */
    private function open(string $prefix = ''): array
    {
        $rows = Database::connect()->table('notifications')->where('resolved_at', null)->orderBy('notification_id')->get()->getResult();

        return array_values(array_filter($rows, static fn (object $row): bool => str_starts_with((string) $row->subject_key, $prefix)));
    }

    private function stock(int $itemId, string $quantity, int $reorder, int $locationId = 1): void
    {
        $db = Database::connect();

        $db->table('items')->where('item_id', $itemId)->update(['reorder_level' => $reorder]);
        $db->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
    }

    private function cheque(string $dueOn, string $status = 'received', int $deleted = 0): int
    {
        $db = Database::connect();

        $db->table('cheques')->insert([
            'direction'     => 'received',
            'status'        => $status,
            'party_id'      => $this->customerId,
            'treasury_id'   => (new TreasuryService())->ensureMain(),
            'cheque_number' => 'CH-' . uniqid(),
            'amount'        => '250.0000',
            'issue_date'    => date('Y-m-d'),
            'due_date'      => $dueOn,
            'deleted'       => $deleted,
        ]);

        return (int) $db->insertID();
    }

    public function testRun_DoesNothingWhileTheNotificationRulesAreOff(): void
    {
        $this->setConfigValue(IntegrationOptions::NOTIFICATIONS, '0');
        $this->rules->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        $run = $this->evaluator->run();

        $this->assertTrue($run->skipped);
        $this->assertSame([], $run->lines);
        $this->assertSame(0, Database::connect()->table('notifications')->countAllResults());
    }

    public function testRun_RaisesOneNotificationForEachInvoiceOverdueByAtLeastTheDaysOfTheRule(): void
    {
        $this->rules->create('invoice_overdue', 'Thirty days', '30', null);

        $old    = $this->invoiceDated('100.00', $this->daysAgo(40));
        $recent = $this->invoiceDated('50.00', $this->daysAgo(3));
        $paid   = $this->invoiceDated('70.00', $this->daysAgo(60), null, null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $today  = $this->invoiceOf('20.00');

        $this->assertTrue($this->evaluator->run()->created() === 1);

        $open = $this->open('invoice:');

        $this->assertSame(['invoice:' . $old], array_map(static fn (object $row): string => $row->subject_key, $open));

        $params = json_decode($open[0]->params, true);

        $this->assertSame('40', $params['days']);
        $this->assertSame('100.0000', $params['amount']);
        $this->assertSame('invoice', $params['kind']);
        $this->assertSame('Omar Khaled', $params['customer']);
        $this->assertSame(1, (int) $open[0]->branch_id);
        $this->assertNotContains('invoice:' . $recent, array_column($open, 'subject_key'));
        $this->assertNotContains('invoice:' . $paid, array_column($open, 'subject_key'));
        $this->assertNotContains('invoice:' . $today, array_column($open, 'subject_key'));
    }

    public function testRun_CountsDaysFromTheDayAnInvoiceFellDueAndRaisesTheBoundaryExactlyOnTheDay(): void
    {
        $this->rules->create('invoice_overdue', 'Five days', '5', null);

        $late    = $this->invoiceDated('10.00', $this->daysAgo(10), 5);
        $notYet  = $this->invoiceDated('10.00', $this->daysAgo(10), 6);
        $onTheDay = $this->invoiceDated('10.00', $this->daysAgo(10), 5);

        $this->evaluator->run();

        $keys = array_column($this->open('invoice:'), 'subject_key');

        $this->assertContains('invoice:' . $late, $keys, 'Overdue by five days.');
        $this->assertContains('invoice:' . $onTheDay, $keys);
        $this->assertNotContains('invoice:' . $notYet, $keys, 'Overdue by four days.');
    }

    public function testRun_MakesNoSecondNotificationOnASecondRunAndTheRowsAreTheSame(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(10));
        $this->invoiceDated('30.00', $this->daysAgo(5));

        $first  = $this->evaluator->run();
        $before = Database::connect()->table('notifications')->orderBy('notification_id')->get()->getResultArray();
        $second = $this->evaluator->run();

        $this->assertSame(2, $first->created());
        $this->assertSame(0, $second->created());
        $this->assertSame(0, $second->resolved());
        $this->assertSame(2, $second->open());
        $this->assertSame($before, Database::connect()->table('notifications')->orderBy('notification_id')->get()->getResultArray());
    }

    public function testRun_ClosesTheNotificationOfAnInvoiceThatIsPaidAndRaisesItAgainWhenThePaymentIsTakenBack(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->evaluator->run();

        $paymentId = $this->addPayment($invoice, '100.00');
        $run       = $this->evaluator->run();

        $this->assertSame(1, $run->resolved());
        $this->assertSame([], $this->open('invoice:'));

        $closed = Database::connect()->table('notifications')->where('subject_key', 'invoice:' . $invoice)->get()->getRow();

        $this->assertNotNull($closed->resolved_at);
        $this->assertNull($closed->open_key);

        $this->removePayment($invoice, $paymentId);
        $this->evaluator->run();

        $this->assertCount(1, $this->open('invoice:'));
        $this->assertSame(2, Database::connect()->table('notifications')->where('subject_key', 'invoice:' . $invoice)->countAllResults(), 'The first one stays closed and a new one is open.');
    }

    public function testRun_RaisesNoOverdueInvoiceAndClosesTheOpenOnesWhileAccountingIsOffAndRaisesThemAgainWhenItIsOn(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->assertSame(1, $this->evaluator->run()->created());

        $this->setConfigValue('accounting_enabled', '0');
        $this->invoiceDated('40.00', $this->daysAgo(20));

        $off = $this->evaluator->run();

        $this->assertSame(0, $off->created(), 'The books are frozen, so nothing new is read from them.');
        $this->assertSame(1, $off->resolved(), 'The notification of an invoice the frozen books still show as open is closed, not kept.');
        $this->assertSame([], $this->open('invoice:'));

        $this->setConfigValue('accounting_enabled', '1');

        $on = $this->evaluator->run();

        $this->assertSame(1, $on->created(), 'The invoice that is still owed is raised again.');
        $this->assertContains('invoice:' . $invoice, array_column($this->open('invoice:'), 'subject_key'));
    }

    public function testRun_StillRaisesLowStockAndChequesWhileAccountingIsOff(): void
    {
        $this->rules->create('low_stock', 'Low', null, null);
        $this->rules->create('cheque_due', 'Cheques', '0', null);
        $this->stock($this->item('1.00'), '1', 5);
        $this->cheque($this->daysAgo(2));
        $this->setConfigValue('accounting_enabled', '0');

        $this->evaluator->run();

        $this->assertNotSame([], $this->open('stock:'));
        $this->assertCount(1, $this->open('cheque:'));
    }

    public function testRun_RaisesTheNotificationOfAnInvoiceOfAnotherBranchInThatBranch(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);

        $second  = $this->createBranch('B2');
        $invoice = $this->invoiceInBranch($second, '60.00');

        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['sale_time' => $this->daysAgo(10) . ' 10:00:00']);
        $this->evaluator->run();

        $this->assertSame($second, (int) $this->open('invoice:' . $invoice)[0]->branch_id);
    }

    public function testRun_SkipsACustomerWhoHoldsMoreCreditThanTheyOwe(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);

        $invoice    = $this->invoiceDated('100.00', $this->daysAgo(10));
        $customerId = (int) Database::connect()->table('sales')->select('customer_id')->where('sale_id', $invoice)->get()->getRow()->customer_id;
        $clerk      = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        (new CustomerAdvances())->receive($customerId, '300.00', date('Y-m-d'), 'Cash', null, '', '', $clerk);
        Database::connect()->table('customer_credit_applications')->where('customer_id', $customerId)->update(['released_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('100.0000', (new CustomerLedger())->position($customerId)->invoices[0]->open(), 'the invoice is open and late while the credit is not spent on it');

        $this->evaluator->run();

        $this->assertSame([], $this->open('invoice:'));
    }

    public function testRun_RaisesALowStockNotificationForEachItemAndLocationAtOrUnderItsReorderLevel(): void
    {
        $this->rules->create('low_stock', 'Low stock', null, null);

        $low   = $this->item('1.00');
        $at    = $this->item('1.00');
        $above = $this->item('1.00');
        $none  = $this->item('1.00', false);

        $this->stock($low, '3', 5);
        $this->stock($at, '5', 5);
        $this->stock($above, '6', 5);
        $this->stock($none, '0', 5);

        $this->evaluator->run();

        $keys = array_column($this->open('stock:'), 'subject_key');

        $this->assertContains("stock:{$low}:1", $keys);
        $this->assertContains("stock:{$at}:1", $keys, 'At the reorder level counts as low, as the low stock report counts it.');
        $this->assertNotContains("stock:{$above}:1", $keys);
        $this->assertNotContains("stock:{$none}:1", $keys, 'An item without stock never runs low.');

        $params = json_decode($this->open("stock:{$low}:")[0]->params, true);

        $this->assertSame('3', $params['quantity']);
        $this->assertSame('5', $params['reorder']);
        $this->assertSame('Test Item', $params['item']);
    }

    public function testRun_ClosesALowStockNotificationWhenTheItemIsRestockedOrDeleted(): void
    {
        $this->rules->create('low_stock', 'Low stock', null, null);

        $restocked = $this->item('1.00');
        $deleted   = $this->item('1.00');

        $this->stock($restocked, '1', 5);
        $this->stock($deleted, '1', 5);
        $this->evaluator->run();

        $this->assertContains("stock:{$restocked}:1", array_column($this->open('stock:'), 'subject_key'));

        $this->stock($restocked, '9', 5);
        $this->accountingDb()->table('items')->where('item_id', $deleted)->update(['deleted' => 1]);

        $run = $this->evaluator->run();

        $this->assertGreaterThanOrEqual(2, $run->resolved());
        $this->assertNotContains("stock:{$restocked}:1", array_column($this->open('stock:'), 'subject_key'));
        $this->assertNotContains("stock:{$deleted}:1", array_column($this->open('stock:'), 'subject_key'));
    }

    public function testRun_KeepsEachLocationOfALowItemOnItsOwnWithTheBranchOfThatLocation(): void
    {
        $this->rules->create('low_stock', 'Low stock', null, null);

        $second   = $this->createBranch('B2');
        $location = $this->branchLocation($second);
        $item     = $this->item('1.00');

        $this->stock($item, '1', 5, 1);
        $this->stock($item, '2', 5, $location);
        $this->evaluator->run();

        $rows = [];

        foreach ($this->open("stock:{$item}:") as $row) {
            $rows[$row->subject_key] = (int) $row->branch_id;
        }

        $this->assertSame(["stock:{$item}:1" => 1, "stock:{$item}:{$location}" => $second], $rows);
    }

    public function testRun_RaisesAChequeNotificationForTheChequesDueWithinTheDaysOfTheRuleAndTheLateOnes(): void
    {
        $this->rules->create('cheque_due', 'Cheques in a week', '7', null);

        $soon      = $this->cheque(date('Y-m-d', strtotime('+3 days')));
        $today     = $this->cheque(date('Y-m-d'));
        $late      = $this->cheque(date('Y-m-d', strtotime('-4 days')));
        $far       = $this->cheque(date('Y-m-d', strtotime('+30 days')));
        $edge      = $this->cheque(date('Y-m-d', strtotime('+7 days')));
        $past      = $this->cheque(date('Y-m-d', strtotime('+8 days')));
        $collected = $this->cheque(date('Y-m-d'), 'collected');
        $rejected  = $this->cheque(date('Y-m-d'), 'rejected');
        $cancelled = $this->cheque(date('Y-m-d'), 'cancelled');
        $deleted   = $this->cheque(date('Y-m-d'), 'received', 1);

        $this->evaluator->run();

        $keys = array_column($this->open('cheque:'), 'subject_key');

        foreach ([$soon, $today, $late, $edge] as $id) {
            $this->assertContains('cheque:' . $id, $keys, "cheque {$id}");
        }

        foreach ([$far, $past, $collected, $rejected, $cancelled, $deleted] as $id) {
            $this->assertNotContains('cheque:' . $id, $keys, "cheque {$id}");
        }

        $params = json_decode($this->open('cheque:' . $soon)[0]->params, true);

        $this->assertSame('250.0000', $params['amount']);
        $this->assertSame(date('Y-m-d', strtotime('+3 days')), $params['due']);
        $this->assertSame('received', $params['direction']);
    }

    public function testRun_RaisesANotificationForEachCertificateOfZatcaThatRunsOutWithinTheDaysOfTheRule(): void
    {
        $db     = Database::connect();
        $unitId = (int) $db->table('einvoice_units')->where(['company_id' => 1, 'is_default' => 1])->get()->getRow()->unit_id;

        $db->table('einvoice_credentials')->insertBatch([
            ['unit_id' => $unitId, 'environment' => 'production', 'kind' => 'production', 'status' => 'active', 'valid_to' => date('Y-m-d H:i:s', strtotime('+20 days'))],
            ['unit_id' => $unitId, 'environment' => 'sandbox', 'kind' => 'compliance', 'status' => 'active', 'valid_to' => date('Y-m-d H:i:s', strtotime('+90 days'))],
            ['unit_id' => $unitId, 'environment' => 'simulation', 'kind' => 'production', 'status' => 'retired', 'valid_to' => date('Y-m-d H:i:s', strtotime('+2 days'))],
        ]);

        $this->rules->create('einvoice_certificate_expiry', 'Certificates in a month', '30', null);
        $this->evaluator->run();

        $open = $this->open('einvoice_certificate:');

        $this->assertCount(1, $open, 'the one in use that runs out within the month; not a retired one, nor one far off');
        $this->assertSame(['environment' => 'production', 'kind' => 'production', 'valid_to' => date('Y-m-d', strtotime('+20 days'))], array_intersect_key(json_decode($open[0]->params, true), array_flip(['environment', 'kind', 'valid_to'])));

        $db->table('einvoice_credentials')->truncate();
        $this->evaluator->run();

        $this->assertSame([], $this->open('einvoice_certificate:'), 'renewed or gone, the notification is closed');
    }

    public function testRun_RaisesANotificationForEachSimplifiedDocumentStillNotReportedHalfADayAfterItWasQueued(): void
    {
        $db = Database::connect();

        foreach ([['simplified', 13 * 3600, 'retry', 'S-LATE'], ['simplified', 3600, 'pending', 'S-NEW'], ['standard', 13 * 3600, 'pending', 'B-LATE'], ['simplified', 13 * 3600, 'reported', 'S-DONE']] as $index => [$subtype, $age, $status, $number]) {
            $db->table('einvoice_documents')->insert(['source_type' => 'sale', 'source_id' => 970000 + $index, 'doc_kind' => 'invoice', 'subtype' => $subtype, 'document_number' => $number, 'uuid' => sprintf('00000000-0000-4000-8000-%012d', 970000 + $index), 'icv' => 970000 + $index, 'previous_hash' => 'x', 'invoice_hash' => 'h', 'issue_date' => date('Y-m-d'), 'issue_time' => '10:00:00', 'tax_exclusive' => '1.00', 'tax_amount' => '0.15', 'tax_inclusive' => '1.15', 'xml' => '<Invoice/>']);
            $db->table('einvoice_outbox')->insert(['document_id' => (int) $db->insertID(), 'company_id' => 1, 'environment' => 'production', 'status' => $status, 'queued_at' => date('Y-m-d H:i:s', time() - $age)]);
        }

        $this->rules->create('einvoice_reporting_late', 'Not reported', null, null);
        $this->evaluator->run();

        $this->assertSame(['S-LATE'], array_map(static fn (object $row): string => json_decode($row->params, true)['document'], $this->open('einvoice_reporting:')));

        $db->table('einvoice_outbox')->truncate();
        $db->table('einvoice_documents')->where('source_id >=', 970000)->delete();
    }

    public function testRun_ClosesTheNotificationOfAChequeThatIsCollected(): void
    {
        $this->rules->create('cheque_due', 'Cheques', '0', null);

        $cheque = $this->cheque(date('Y-m-d'));

        $this->evaluator->run();

        $this->assertCount(1, $this->open('cheque:'));

        Database::connect()->table('cheques')->where('cheque_id', $cheque)->update(['status' => 'collected']);
        $this->evaluator->run();

        $this->assertSame([], $this->open('cheque:'));
    }

    public function testRun_KeepsTheNotificationsOfTwoRulesApartAndAllowsTwoRulesOfOneKind(): void
    {
        $this->rules->create('invoice_overdue', 'One day', '1', null);
        $this->rules->create('invoice_overdue', 'Thirty days', '30', null);

        $this->invoiceDated('100.00', $this->daysAgo(40));
        $this->invoiceDated('100.00', $this->daysAgo(5));

        $run = $this->evaluator->run();

        $this->assertSame(3, $run->created(), 'Two for the first rule and one for the second.');
        $this->assertSame(3, $run->open());
    }

    public function testRun_ClosesWhatASwitchedOffRuleRaisedAndRaisesNothingForIt(): void
    {
        $id = $this->rules->create('invoice_overdue', 'Late', '1', null);

        $this->invoiceDated('100.00', $this->daysAgo(10));
        $this->evaluator->run();

        $this->assertCount(1, $this->open('invoice:'));

        $this->rules->setActive($id, false, null);

        $run = $this->evaluator->run();

        $this->assertSame(1, $run->resolved());
        $this->assertSame(0, $run->created());
        $this->assertSame([], $this->open('invoice:'));

        $this->rules->setActive($id, true, null);
        $this->evaluator->run();

        $this->assertCount(1, $this->open('invoice:'));
    }

    public function testRun_ChangesNeitherAJournalNorASaleNorAnItem(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);
        $this->rules->create('low_stock', 'Low', null, null);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        $db     = Database::connect();
        $tables = ['accounting_journals', 'accounting_journal_lines', 'sales', 'sales_items', 'sales_payments', 'items', 'item_quantities', 'inventory', 'customers', 'accounting_accounts'];
        $before = [];

        foreach ($tables as $table) {
            $before[$table] = $db->query('CHECKSUM TABLE ' . $db->prefixTable($table))->getRow()->Checksum;
        }

        $this->evaluator->run();
        $this->evaluator->run();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], $db->query('CHECKSUM TABLE ' . $db->prefixTable($table))->getRow()->Checksum, $table);
        }
    }

    public function testRun_RaisesTheRulesOfADayThatIsGivenAsTheDayOfTheShop(): void
    {
        $this->rules->create('invoice_overdue', 'Late', '1', null);
        $this->invoiceDated('100.00', $this->daysAgo(1), 1);

        $this->assertSame(0, $this->evaluator->run(date('Y-m-d'))->created(), 'Due today, so not yet overdue.');
        $this->assertSame(1, $this->evaluator->run(date('Y-m-d', strtotime('+2 days')))->created(), 'Two days later it is overdue by two.');
    }

    public function testNamespace_CallsNoMailNoHttpAndNoSocketFunctionAndUsesNoOutboundClass(): void
    {
        $banned = [
            'mail', 'curl_init', 'curl_exec', 'curl_multi_init', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create', 'socket_connect',
            'get_headers', 'http_get', 'http_post_data', 'ftp_connect', 'ssh2_connect', 'imap_open', 'mb_send_mail', 'file_get_contents', 'file_put_contents', 'fopen', 'stream_context_create',
        ];
        $bannedWords = ['Email', 'Email_lib', 'MY_Email', 'Sms_lib', 'Mailchimp_lib', 'CURLRequest', 'curlrequest', 'Client', 'HttpClient', 'Webhook', 'webhook'];
        $files       = [...glob(APPPATH . 'Libraries/Integrations/*.php'), APPPATH . 'Commands/NotificationsEvaluate.php'];

        $this->assertGreaterThan(15, count($files));

        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count  = count($tokens);

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_STRING && in_array(strtolower($token[1]), $banned, true)) {
                    $next = $index + 1;

                    while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }

                    $this->assertFalse(($tokens[$next] ?? null) === '(', basename($file) . ' calls ' . $token[1]);
                }

                if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                    foreach ($bannedWords as $word) {
                        $this->assertNotSame($word, trim($token[1], "'\""), basename($file) . ' names ' . $word);
                    }

                    $this->assertDoesNotMatchRegularExpression('/(^|\\\\)(Email|CURLRequest|Sms_lib|Mailchimp_lib|MY_Email|Email_lib)$/', $token[1], basename($file) . ' reaches ' . $token[1]);
                    $this->assertDoesNotMatchRegularExpression('#^[\'"]?https?://#i', $token[1], basename($file) . ' names an address');
                }
            }
        }
    }
}
