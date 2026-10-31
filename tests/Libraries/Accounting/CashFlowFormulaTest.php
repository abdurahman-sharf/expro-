<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CashFlowFormula;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What a line of the cash flow report takes from an account that was debited and credited.
 */
final class CashFlowFormulaTest extends CIUnitTestCase
{
    public function testOf_TakesWhatWasCreditedLessWhatWasDebitedByCreditInPeriod(): void
    {
        $this->assertSame('-7336.0600', CashFlowFormula::CreditInPeriod->of('7336.06', '0'));
        $this->assertSame('15.0000', CashFlowFormula::CreditInPeriod->of('5', '20'));
    }

    public function testOf_TakesWhatWasDebitedLessWhatWasCreditedByDebitInPeriod(): void
    {
        $this->assertSame('15.0000', CashFlowFormula::DebitInPeriod->of('20', '5'));
        $this->assertSame('-15.0000', CashFlowFormula::DebitInPeriod->of('5', '20'));
    }

    public function testOf_TakesOnlyOneSideByTheTwoFormulasTheReferenceSystemDoesNotNameForAssets(): void
    {
        $this->assertSame('20.0000', CashFlowFormula::CreditsInPeriod->of('5', '20'));
        $this->assertSame('-5.0000', CashFlowFormula::MinusDebitsInPeriod->of('5', '20'));
    }
}
