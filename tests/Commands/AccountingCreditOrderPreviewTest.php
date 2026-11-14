<?php

namespace Tests\Commands;

use App\Libraries\Accounting\CreditSource;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use ReflectionProperty;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\CreditSourceFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The command that prints which open items would have their credit moved by the order of E57: what it says
 * for a customer with nothing to move and for one with credit where the order would not put it, the same as
 * JSON, that it changes no row, and that it refuses a customer that is not a number.
 *
 * @internal
 */
final class AccountingCreditOrderPreviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use CreditSourceFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopCreditSources();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function preview(array $params = []): array
    {
        $this->resetStreamFilterBuffer();

        $exit = service('commands')->run('accounting:credit_order_preview', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    /**
     * An older invoice and a newer one, and a credit note of the newer that stands on it where the order would put it on the older.
     *
     * @return array{int, int, int}
     */
    private function creditOnTheWrongInvoice(): array
    {
        $older = $this->invoiceDated('100.00', $this->daysAgo(10));
        $newer = $this->invoiceDated('100.00', $this->daysAgo(5));
        $note  = $this->creditNoteOf($newer, '60.00');

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->update(['invoice_sale_id' => $newer]);

        return [$older, $newer, $note];
    }

    public function testRun_SaysNothingWouldMoveForCustomersWhoseCreditIsWhereTheOrderPutsIt(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $this->invoiceDated('60.00', $this->daysAgo(5));

        [$exit, $output] = $this->preview();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No open item would have its credit moved', $output);
    }

    public function testRun_PrintsTheItemsWhoseCreditIsNotWhereTheOrderPutsItAndChangesNothing(): void
    {
        [$older, $newer, $note] = $this->creditOnTheWrongInvoice();

        $print = $this->databaseFingerprint();

        [$exit, $output] = $this->preview();

        $this->assertSame(0, $exit);
        $this->assertDatabaseUnchanged($print);
        $this->assertStringContainsString('Customer ' . $this->customerId . ' Omar Khaled', $output);
        $this->assertStringContainsString('Not where the order puts it: invoice ' . $older . ' of ' . $this->daysAgo(10) . ': 0.0000 -> 60.0000 (none -> credit_note:' . $note . ' 60.00)', $output);
        $this->assertStringContainsString('Not where the order puts it: invoice ' . $newer . ' of ' . $this->daysAgo(5) . ': 60.0000 -> 0.0000 (credit_note:' . $note . ' 60.00 -> none)', $output);
        $this->assertStringNotContainsString('A run would move', $output);
    }

    public function testRun_PrintsWhatTheNextRunWouldMoveWhenCreditIsFreeAndAnItemIsOpen(): void
    {
        $advance = $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE, '100.00');
        $invoice = $this->invoiceDated('60.00', $this->daysAgo(5));

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->delete();

        [, $output] = $this->preview(['customer' => (string) $this->customerId]);

        $this->assertStringContainsString('A run would move invoice ' . $invoice . ' of ' . $this->daysAgo(5) . ': 0.0000 -> 60.0000 (none -> customer_advance:' . $advance . ' 60.00)', $output);
    }

    public function testRun_PrintsTheSameAsJsonForOneCustomerOrForAll(): void
    {
        [$older, , $note] = $this->creditOnTheWrongInvoice();

        [$exit, $output] = $this->preview(['json' => null]);
        $all             = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        [, $one] = $this->preview(['json' => null, 'customer' => (string) $this->customerId]);

        $this->assertSame(0, $exit);
        $this->assertSame($all, json_decode($one, true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $all);
        $this->assertSame([$this->customerId, 'Omar Khaled', null, []], [$all[0]['customer_id'], $all[0]['name'], $all[0]['blocked'], $all[0]['moves']]);
        $this->assertSame(['invoice', $older, [], [CreditSource::CREDIT_NOTE . ':' . $note => '60.0000']], [$all[0]['differences'][0]['kind'], $all[0]['differences'][0]['id'], $all[0]['differences'][0]['before'], $all[0]['differences'][0]['after']]);
    }

    public function testRun_RefusesACustomerThatIsNotANumberAndPrintsNothingOfTheBooks(): void
    {
        $this->creditOnTheWrongInvoice();

        foreach (['abc', '0', '-3', '1.5'] as $customer) {
            [$exit, $output] = $this->preview(['customer' => $customer]);

            $this->assertSame(EXIT_USER_INPUT, $exit, $customer);
            $this->assertStringNotContainsString('Not where the order puts it', $output);
        }
    }

    public function testRun_RefusesTheCustomerOptionGivenWithoutANumberInEitherForm(): void
    {
        $this->creditOnTheWrongInvoice();

        foreach ([['customer' => null], ['customer=' => null], ['customer=abc' => null], ['customer=0' => null], ['customer=1.5' => null]] as $params) {
            [$exit, $output] = $this->preview($params);

            $this->assertSame(EXIT_USER_INPUT, $exit, json_encode($params));
            $this->assertStringNotContainsString('Not where the order puts it', $output);
            $this->assertStringNotContainsString('No open item', $output);
        }
    }

    public function testRun_RefusesTheCustomerOptionTheCommandLineParsedWithoutAValueAndDoesNotReadItAsCustomerOne(): void
    {
        $this->creditOnTheWrongInvoice();

        $options = new ReflectionProperty(CLI::class, 'options');
        $was     = $options->getValue();

        $options->setValue(null, ['customer' => null]);

        try {
            [$exit, $output] = $this->preview(['customer' => null]);
        } finally {
            $options->setValue(null, $was);
        }

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertStringNotContainsString('Customer ' . $this->customerId, $output);
        $this->assertStringNotContainsString('No open item', $output);
    }

    public function testRun_ReadsTheCustomerGivenAsTheOptionEqualsTheNumberAndNoOtherCustomer(): void
    {
        $this->creditOnTheWrongInvoice();

        $other = $this->anotherCustomer();

        [$exit, $output] = $this->preview(['customer=' . $other => null]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No open item would have its credit moved', $output);
        $this->assertStringNotContainsString('Not where the order puts it', $output);

        [$exit, $output] = $this->preview(['customer=' . $this->customerId => null]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Customer ' . $this->customerId . ' Omar Khaled', $output);
    }

    public function testRun_ReadsACustomerWithNothingToSayAsNothingToMove(): void
    {
        [$exit, $output] = $this->preview(['customer' => '987654']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No open item would have its credit moved', $output);
    }
}
