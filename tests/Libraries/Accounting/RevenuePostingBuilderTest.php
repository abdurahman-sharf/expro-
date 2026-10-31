<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RevenueDocument;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The journal a saved receipt voucher needs, as a draft: the whole amount debited to the treasury
 * it came into, what it came to without its tax credited to the account chosen on it, its category
 * or the routed account of other revenue, and the tax in it credited to the tax collected.
 */
class RevenuePostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RevenuePostingBuilder $builder;
    private int $treasuryId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->builder    = new RevenuePostingBuilder(new RoutingResolver());
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Builder drawer', 'kind' => 'cash']);
    }

    protected function tearDown(): void
    {
        $this->clearJournals();

        parent::tearDown();
    }

    private function voucher(string $amount, string $tax = '0', string $payment = 'Cash', ?int $accountId = null, ?int $categoryAccountId = null, ?int $treasuryId = -1): RevenueDocument
    {
        return new RevenueDocument(
            revenueId: 12,
            date: '2026-10-20',
            amount: bcadd($amount, '0', 4),
            taxAmount: bcadd($tax, '0', 4),
            paymentType: $payment,
            accountId: $accountId,
            categoryAccountId: $categoryAccountId,
            treasuryId: $treasuryId === -1 ? $this->treasuryId : $treasuryId,
            branchId: 3,
            deleted: false,
        );
    }

    /**
     * @return list<string> "account:debit:credit" in line order.
     */
    private function linesOf(?JournalDraft $draft): array
    {
        $this->assertNotNull($draft);

        return array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    private function refusalOf(callable $work): PostingException
    {
        try {
            $work();
        } catch (PostingException $exception) {
            return $exception;
        }

        $this->fail('The voucher was not refused.');
    }

    public function testRevenue_PutsTheWholeAmountIntoTheTreasuryAndCreditsTheNetAndTheTax(): void
    {
        $draft = $this->builder->revenue($this->voucher('115.00', '15.00'), 4, 9);

        $this->assertSame([
            (new TreasuryService())->accountOf($this->treasuryId) . ':115.0000:0.0000',
            $this->accountId('revenues_default') . ':0.0000:100.0000',
            $this->accountId('tax_output') . ':0.0000:15.0000',
        ], $this->linesOf($draft));
        $this->assertSame(RevenuePostingBuilder::SOURCE, $draft->sourceType);
        $this->assertSame(12, $draft->sourceId);
        $this->assertSame(4, $draft->revision);
        $this->assertSame(3, $draft->branchId);
        $this->assertSame(9, $draft->employeeId);
        $this->assertSame('2026-10-20', $draft->date);
        $this->assertSame('Receipt voucher #12', $draft->description);
        $this->assertSame(JournalOrigin::System, $draft->origin);
        $this->assertSame(JournalEntryType::General, $draft->entryType);
    }

    public function testRevenue_PostsTwoLinesWhenThereIsNoTax(): void
    {
        $draft = $this->builder->revenue($this->voucher('100.00'), 1);

        $this->assertSame([
            (new TreasuryService())->accountOf($this->treasuryId) . ':100.0000:0.0000',
            $this->accountId('revenues_default') . ':0.0000:100.0000',
        ], $this->linesOf($draft));
    }

    public function testRevenue_PostsNoRevenueLineWhenTheTaxIsTheWholeAmount(): void
    {
        $draft = $this->builder->revenue($this->voucher('15.00', '15.00'), 1);

        $this->assertSame([
            (new TreasuryService())->accountOf($this->treasuryId) . ':15.0000:0.0000',
            $this->accountId('tax_output') . ':0.0000:15.0000',
        ], $this->linesOf($draft));
    }

    public function testRevenue_KeepsTheTaxExactlyWithoutDriftAtFourDecimals(): void
    {
        $draft = $this->builder->revenue($this->voucher('115.33', '15.04'), 1);

        $this->assertSame('115.3300', $draft->lines[0]->debit);
        $this->assertSame(['100.2900', '15.0400'], [$draft->lines[1]->credit, $draft->lines[2]->credit]);
    }

    public function testRevenue_CreditsTheChosenAccountThenTheCategoryThenTheRoutedOne(): void
    {
        $chosen   = $this->customAccount('42', '4291', 'Chosen revenue', false);
        $category = $this->customAccount('42', '4292', 'Category revenue', false);

        $this->assertSame($chosen, $this->builder->revenue($this->voucher('100.00', '0', 'Cash', $chosen, $category), 1)->lines[1]->accountId);
        $this->assertSame($category, $this->builder->revenue($this->voucher('100.00', '0', 'Cash', null, $category), 1)->lines[1]->accountId);
        $this->assertSame($this->accountId('revenues_default'), $this->builder->revenue($this->voucher('100.00'), 1)->lines[1]->accountId);
        $this->assertSame($chosen, $this->builder->revenue($this->voucher('100.00', '0', 'Cash', $chosen), 1)->lines[1]->accountId);
    }

    public function testRevenue_IsReceivedIntoTheTreasuryItNamesOrTheAccountOfItsWayOfPayment(): void
    {
        $this->assertSame((int) (new TreasuryService())->accountOf($this->treasuryId), $this->builder->revenue($this->voucher('100.00'), 1)->lines[0]->accountId);
        $this->assertSame($this->accountId('cash'), $this->builder->revenue($this->voucher('100.00', '0', 'Cash', null, null, null), 1)->lines[0]->accountId);
    }

    public function testRevenue_ReturnsNothingForAnAmountThatIsNotMoreThanZero(): void
    {
        $this->assertNull($this->builder->revenue($this->voucher('0.00'), 1));
    }

    public function testRevenue_RefusesATaxBelowZeroOrAboveTheAmount(): void
    {
        $this->assertSame(PostingError::RevenueTaxInvalid, $this->refusalOf(fn () => $this->builder->revenue($this->voucher('100.00', '100.01'), 1))->error);
        $this->assertSame(PostingError::RevenueTaxInvalid, $this->refusalOf(fn () => $this->builder->revenue($this->voucher('100.00', '-5.00'), 1))->error);
        $this->assertNotNull($this->builder->revenue($this->voucher('100.00', '100.00'), 1));
        $this->assertNotNull($this->builder->revenue($this->voucher('100.00', '0.00'), 1));
    }

    public function testRevenue_RefusesAWayOfPaymentThatIsNotMoney(): void
    {
        foreach ([lang(PaymentType::Giftcard->languageKey()), lang(PaymentType::Rewards->languageKey()), lang(PaymentType::Due->languageKey()), 'Barter'] as $label) {
            $this->assertSame(PostingError::PaymentTypeUnknown, $this->refusalOf(fn () => $this->builder->revenue($this->voucher('100.00', '0', $label, null, null, null), 1))->error, $label);
        }

        $this->assertStringContainsString('is not one a receipt voucher is received with', $this->refusalOf(fn () => $this->builder->revenue($this->voucher('100.00', '0', lang(PaymentType::Due->languageKey()), null, null, null), 1))->getMessage());
    }

    public function testRevenue_ReceivesAnyOtherWayOfPaymentLikeCash(): void
    {
        foreach ([PaymentType::Debit, PaymentType::Credit, PaymentType::Check, PaymentType::BankTransfer, PaymentType::Upi, PaymentType::Wallet] as $type) {
            $draft = $this->builder->revenue($this->voucher('100.00', '0', lang($type->languageKey()), null, null, null), 1);

            $this->assertNotNull($draft, $type->value);
            $this->assertSame('100.0000', $draft->lines[0]->debit, $type->value);
        }
    }
}
