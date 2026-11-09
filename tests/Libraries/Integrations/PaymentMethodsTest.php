<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\PaymentMethod;
use App\Libraries\Integrations\PaymentMethods;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The payment methods the register offers: which are switched off, under what name the others are shown,
 * that the name a payment is stored under never changes, that the routing of the books is read and never
 * written, and that none of it applies while the switch of custom payment methods is off.
 */
class PaymentMethodsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PaymentMethods $methods;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->methods = new PaymentMethods();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->stopSelling();
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

    /**
     * @return array<string, string>
     */
    private function standardOptions(): array
    {
        return [
            lang('Sales.cash')          => lang('Sales.cash'),
            lang('Sales.debit')         => lang('Sales.debit'),
            lang('Sales.credit')        => lang('Sales.credit'),
            lang('Sales.due')           => lang('Sales.due'),
            lang('Sales.check')         => lang('Sales.check'),
            lang('Sales.bank_transfer') => lang('Sales.bank_transfer'),
            lang('Sales.wallet')        => lang('Sales.wallet'),
            lang('Sales.giftcard')      => lang('Sales.giftcard'),
            lang('Sales.rewards')       => lang('Sales.rewards'),
        ];
    }

    public function testAll_ListsTheSevenManagedWaysOfPaymentOfferedAndUnnamedWhenNothingIsSet(): void
    {
        $all = $this->methods->all();

        $this->assertSame(
            ['cash', 'debit', 'credit', 'check', 'bank_transfer', 'upi', 'wallet'],
            array_map(static fn (PaymentMethod $method): string => $method->type->value, $all),
        );

        foreach ($all as $method) {
            $this->assertTrue($method->enabled);
            $this->assertNull($method->customName);
            $this->assertSame($method->defaultName, $method->shownAs());
            $this->assertSame($method->type !== PaymentType::Cash, $method->mayBeSwitchedOff);
        }
    }

    public function testOffered_ReturnsTheOptionsAsTheyAreWhileTheSwitchIsOffWhateverTheTableHolds(): void
    {
        $this->methods->save(['debit' => ['enabled' => '0'], 'wallet' => ['name' => 'E-wallet']], null);

        $this->assertSame($this->standardOptions(), $this->methods->offered($this->standardOptions()));
    }

    public function testOffered_DropsTheSwitchedOffWaysAndShowsTheChosenNamesKeepingTheStoredKeysWhileTheSwitchIsOn(): void
    {
        $this->methods->save(['debit' => ['enabled' => '0'], 'wallet' => ['name' => 'E-wallet'], 'check' => ['name' => 'Cheque']], null);
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $offered = $this->methods->offered($this->standardOptions());

        $this->assertArrayNotHasKey(lang('Sales.debit'), $offered);
        $this->assertSame('E-wallet', $offered[lang('Sales.wallet')]);
        $this->assertSame('Cheque', $offered[lang('Sales.check')]);
        $this->assertSame(lang('Sales.cash'), $offered[lang('Sales.cash')]);
        $this->assertSame(lang('Sales.credit'), $offered[lang('Sales.credit')]);
        $this->assertSame(array_keys($offered), array_values(array_diff(array_keys($this->standardOptions()), [lang('Sales.debit')])), 'The order of the options is the order they came in.');
    }

    public function testOffered_NeverTouchesCreditSaleGiftCardsAndRewardPoints(): void
    {
        $this->methods->save(['wallet' => ['enabled' => '0']], null);
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $offered = $this->methods->offered($this->standardOptions());

        foreach (['Sales.due', 'Sales.giftcard', 'Sales.rewards'] as $key) {
            $this->assertSame(lang($key), $offered[lang($key)], $key);
        }
    }

    public function testOffered_LeavesAnOptionThatIsNoWayOfPaymentAlone(): void
    {
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $this->assertSame(['Something else' => 'Something else'], $this->methods->offered(['Something else' => 'Something else']));
    }

    public function testSave_SwitchesAWayOffAndAuditsItWithWhatItWas(): void
    {
        $manager = $this->integrationManager();

        $this->methods->save(['bank_transfer' => ['enabled' => '0']], $manager);

        $row = Database::connect()->table('payment_methods')->where('method_code', 'bank_transfer')->get()->getRow();

        $this->assertSame(0, (int) $row->enabled);
        $this->assertNull($row->display_name);
        $this->assertSame($manager, (int) $row->updated_by);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'payment_methods_updated')->get()->getRow();

        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(
            ['before' => ['bank_transfer' => ['enabled' => true, 'name' => null]], 'after' => ['bank_transfer' => ['enabled' => false, 'name' => null]]],
            json_decode($entry->details, true),
        );
    }

    public function testSave_RenamesAWayAndABlankNameGoesBackToTheStandardOne(): void
    {
        $this->methods->save(['wallet' => ['name' => '  Mobile   wallet ']], null);

        $this->assertSame('Mobile wallet', $this->wallet()->customName);
        $this->assertSame('Mobile wallet', $this->wallet()->shownAs());

        $this->methods->save(['wallet' => ['name' => '   ']], null);

        $this->assertNull($this->wallet()->customName);
        $this->assertSame($this->wallet()->defaultName, $this->wallet()->shownAs());
    }

    private function wallet(): PaymentMethod
    {
        foreach ($this->methods->all() as $method) {
            if ($method->type === PaymentType::Wallet) {
                return $method;
            }
        }

        $this->fail('The wallet is not listed.');
    }

    public function testSave_KeepsAWayThatIsLeftOutAsItIs(): void
    {
        $this->methods->save(['wallet' => ['enabled' => '0', 'name' => 'Pay']], null);
        $this->methods->save(['wallet' => ['name' => 'Pay2']], null);

        $this->assertFalse($this->wallet()->enabled, 'Only the name was sent.');
        $this->assertSame('Pay2', $this->wallet()->customName);
    }

    public function testSave_RejectsSwitchingCashOff(): void
    {
        $refusal = $this->refusal(fn () => $this->methods->save(['cash' => ['enabled' => '0']], null));

        $this->assertSame(IntegrationError::PaymentMethodProtected, $refusal->error);
        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
    }

    public function testSave_AcceptsRenamingCash(): void
    {
        $this->methods->save(['cash' => ['name' => 'Cash drawer', 'enabled' => '1']], null);

        $this->assertSame('Cash drawer', $this->methods->all()[0]->customName);
        $this->assertTrue($this->methods->all()[0]->enabled);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unmanagedCodes(): array
    {
        return ['credit sale' => ['due'], 'gift card' => ['giftcard'], 'reward points' => ['rewards'], 'nothing' => ['nonsense'], 'empty' => ['']];
    }

    #[DataProvider('unmanagedCodes')]
    public function testSave_RejectsAWayThatThisScreenDoesNotManage(string $code): void
    {
        $refusal = $this->refusal(fn () => $this->methods->save([$code => ['enabled' => '0']], null));

        $this->assertSame(IntegrationError::PaymentMethodUnknown, $refusal->error);
        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badNames(): array
    {
        return ['too long' => [str_repeat('n', 61)], 'a colon' => ['Pay: now'], 'markup' => ['<b>Pay</b>'], 'a control character' => ["Pay\x07"]];
    }

    #[DataProvider('badNames')]
    public function testSave_RejectsANameThatIsNotShortPlainText(string $name): void
    {
        $refusal = $this->refusal(fn () => $this->methods->save(['wallet' => ['name' => $name]], null));

        $this->assertSame(IntegrationError::PaymentMethodName, $refusal->error);
        $this->assertSame(60, $refusal->context['max']);
    }

    public function testSave_AcceptsANameOfExactlySixtyCharacters(): void
    {
        $this->methods->save(['wallet' => ['name' => str_repeat('n', 60)]], null);

        $this->assertSame(60, mb_strlen((string) $this->wallet()->customName));
    }

    public function testSave_RejectsANameTwoWaysWouldShareWhateverTheCase(): void
    {
        $this->methods->save(['wallet' => ['name' => 'Pay']], null);

        $refusal = $this->refusal(fn () => $this->methods->save(['check' => ['name' => 'PAY']], null));

        $this->assertSame(IntegrationError::PaymentMethodNameTaken, $refusal->error);
        $this->assertSame('pay', mb_strtolower($refusal->context['name']));
    }

    public function testSave_RejectsTheStandardNameOfAnotherWayAndTheNameOfCreditSale(): void
    {
        $standard = $this->refusal(fn () => $this->methods->save(['wallet' => ['name' => lang('Sales.cash')]], null));
        $credit   = $this->refusal(fn () => $this->methods->save(['wallet' => ['name' => lang('Sales.due')]], null));

        $this->assertSame(IntegrationError::PaymentMethodNameTaken, $standard->error);
        $this->assertSame(IntegrationError::PaymentMethodNameTaken, $credit->error);
        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
    }

    public function testSave_AcceptsGivingAWayItsOwnStandardNameBack(): void
    {
        $this->methods->save(['wallet' => ['name' => lang('Sales.wallet')]], null);

        $this->assertSame(lang('Sales.wallet'), $this->wallet()->shownAs());
    }

    public function testSave_WritesNothingForARefusedBatch(): void
    {
        $this->refusal(fn () => $this->methods->save(['debit' => ['enabled' => '0'], 'cash' => ['enabled' => '0']], null));

        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
        $this->assertSame([], $this->auditActions('payment_methods'));
    }

    public function testSave_RequiresThePermissionToManage(): void
    {
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $refusal = $this->refusal(fn () => $this->methods->save(['debit' => ['enabled' => '0']], $outsider));

        $this->assertSame(IntegrationError::PermissionDenied, $refusal->error);
        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
    }

    public function testSave_WritesNothingAndAuditsNothingWhenNothingChanges(): void
    {
        $this->methods->save(['debit' => ['enabled' => '1', 'name' => '']], null);
        $this->methods->save(['debit' => ['enabled' => '0']], null);
        $this->methods->save(['debit' => ['enabled' => '0']], null);

        $this->assertSame(['payment_methods_updated'], $this->auditActions('payment_methods'));
    }

    public function testIsOffered_RefusesASwitchedOffWayOnlyWhileTheSwitchIsOn(): void
    {
        $this->methods->save(['debit' => ['enabled' => '0']], null);

        $this->assertTrue($this->methods->isOffered(lang('Sales.debit')), 'The switch is off.');

        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $this->assertFalse($this->methods->isOffered(lang('Sales.debit')));
        $this->assertFalse($this->methods->isOffered(lang('Sales.debit', [], 'en')), 'The name in the fallback language is the same way.');
        $this->assertTrue($this->methods->isOffered(lang('Sales.credit')));
        $this->assertTrue($this->methods->isOffered(lang('Sales.cash')));
    }

    public function testIsOffered_AcceptsWhatIsNotAManagedWay(): void
    {
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);
        $this->methods->save(['wallet' => ['enabled' => '0']], null);

        $this->assertTrue($this->methods->isOffered(lang('Sales.due')));
        $this->assertTrue($this->methods->isOffered(lang('Sales.giftcard') . ':123456'));
        $this->assertTrue($this->methods->isOffered(lang('Sales.rewards')));
        $this->assertTrue($this->methods->isOffered('Unknown way'));
    }

    public function testAssertOffered_NamesTheSwitchedOffWay(): void
    {
        $this->methods->save(['debit' => ['enabled' => '0']], null);
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $refusal = $this->refusal(fn () => $this->methods->assertOffered(lang('Sales.debit')));

        $this->assertSame(IntegrationError::PaymentMethodDisabled, $refusal->error);
        $this->assertSame(lang('Sales.debit'), $refusal->context['name']);
        $this->methods->assertOffered(lang('Sales.credit'));
        $this->addToAssertionCount(1);
    }

    public function testRoutedTo_ShowsTheAccountEachWayIsRoutedToAndTheTreasuryThatOwnsIt(): void
    {
        $this->startSelling();

        $cash = $this->methods->routedTo(PaymentType::Cash);

        $this->assertNotNull($cash);
        $this->assertNotSame('', $cash['account']);
        $this->assertNull($cash['treasury']);

        $treasuries = new TreasuryService();
        $manager    = $this->employeeWithGrants(['acc_treasuries_manage']);
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $treasuries->accountOf($bank)]);

        $this->assertSame('Routed bank', $this->methods->routedTo(PaymentType::BankTransfer)['treasury']);
    }

    public function testRoutedTo_ReadsTheRoutingAndWritesNothing(): void
    {
        $this->startSelling();

        $before = $this->accountingDb()->table('accounting_routing')->orderBy('routing_key')->get()->getResultArray();

        $this->methods->routedTo(PaymentType::Cash);
        $this->methods->save(['cash' => ['name' => 'Drawer'], 'debit' => ['enabled' => '0']], null);

        $this->assertSame($before, $this->accountingDb()->table('accounting_routing')->orderBy('routing_key')->get()->getResultArray());
    }

    public function testRoutedTo_ReturnsNothingWhenTheWayIsRoutedNowhere(): void
    {
        $this->reinstallChart();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.wallet')->delete();

        $this->assertNull($this->methods->routedTo(PaymentType::Wallet));
    }
}
