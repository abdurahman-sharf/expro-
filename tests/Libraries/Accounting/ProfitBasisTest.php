<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ProfitBasis;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParityCaseTrait;

/**
 * The basis a profit and loss statement is asked for in: accrual by default, cash under either of its
 * two names, and anything else refused in words.
 */
final class ProfitBasisTest extends CIUnitTestCase
{
    use ParityCaseTrait;

    public function testFromRequest_ReadsNothingOrAnEmptyTextAsTheAccrualBasis(): void
    {
        $this->assertSame(ProfitBasis::Accrual, ProfitBasis::fromRequest(null));
        $this->assertSame(ProfitBasis::Accrual, ProfitBasis::fromRequest(''));
        $this->assertSame(ProfitBasis::Accrual, ProfitBasis::fromRequest('  '));
    }

    public function testFromRequest_AcceptsTheBasesByTheirNamesAndThePaymentDateAsAnotherNameOfCash(): void
    {
        $this->assertSame(ProfitBasis::Accrual, ProfitBasis::fromRequest('accrual'));
        $this->assertSame(ProfitBasis::Cash, ProfitBasis::fromRequest('cash'));
        $this->assertSame(ProfitBasis::Cash, ProfitBasis::fromRequest(' payment '));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedProvider(): array
    {
        return [
            'invoice issue date, all invoices'  => ['amount'],
            'invoice issue date, paid invoices' => ['paid'],
            'unknown'                           => ['gross'],
            'a list'                            => [['cash', 'accrual']],
            'a number'                          => [1],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testFromRequest_RejectsABasisThatIsNotBuiltByName(mixed $value): void
    {
        try {
            ProfitBasis::fromRequest($value);
            $this->fail('A basis that does not exist was accepted.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ProfitBasisUnavailable, $refusal->error);
        }
    }

    public function testFromRequest_RefusesTheTwoBasesOfTheInvoiceDateByNameWhereTheReferenceSystemComputesThem(): void
    {
        $case = $this->referenceCase('T-68', 'cash_profit', 'the-bases-of-the-invoice-date-are-refused-by-name', 'differs');

        foreach ($case['inputs']['bases'] as $basis) {
            try {
                ProfitBasis::fromRequest($basis);
                $this->fail("The basis {$basis} was accepted.");
            } catch (AccountingException $refusal) {
                $this->assertSameAsFixture($case['expected'], ['status' => 'refused', 'error' => $refusal->error->value], "T-68 the basis {$basis} is refused by name");
            }
        }
    }
}
