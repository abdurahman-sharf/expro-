<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\TaxBasis;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxBasisTest extends CIUnitTestCase
{
    public function testFromRequest_AcceptsNoBasisAsTheAccrualBasis(): void
    {
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest(''));
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest('   '));
    }

    public function testFromRequest_AcceptsTheAccrualBasisWithBlanksAround(): void
    {
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest('accrual'));
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest(' accrual '));
    }

    public function testFromRequest_NamesTheCashBasisSoThatTheReportRefusesItAndDoesNotTakeItForAccrual(): void
    {
        $this->assertSame(TaxBasis::Cash, TaxBasis::fromRequest('cash'));
        $this->assertNotSame(TaxBasis::Accrual, TaxBasis::fromRequest('cash'));
    }

    public function testFromRequest_AcceptsNoValueAtAllAsTheAccrualBasis(): void
    {
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest(null));
    }

    public function testFromRequest_RejectsABasisThatIsNotTextSoThatAListIsNotTakenForNoBasis(): void
    {
        foreach ([['cash'], ['partial'], [], ['a' => 'accrual'], 1, true] as $value) {
            try {
                TaxBasis::fromRequest($value);
                $this->fail('A basis that is ' . get_debug_type($value) . ' was taken');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::TaxBasisUnavailable, $exception->error);
                $this->assertSame(get_debug_type($value), $exception->context['basis']);
            }
        }
    }

    public function testFromRequest_RejectsABasisTheReportDoesNotKnow(): void
    {
        foreach (['paid', 'partial', 'Accrual', 'CASH', '0', 'accrual,cash'] as $value) {
            try {
                TaxBasis::fromRequest($value);
                $this->fail('The basis ' . $value . ' was taken');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::TaxBasisUnavailable, $exception->error);
                $this->assertSame($value, $exception->context['basis']);
            }
        }
    }
}
