<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ForeignAmounts;
use App\Libraries\Accounting\FamilyReports\PurchaseDocument;
use App\Libraries\Accounting\FamilyReports\TieCheck;
use App\Libraries\Accounting\ReceivingType;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ForeignAmountsTest extends CIUnitTestCase
{
    private function document(int $id, ?string $currencyCode): PurchaseDocument
    {
        return new PurchaseDocument($id, (string) $id, '2026-10-01', ReceivingType::Receive, null, '', '', null, '', 1, 'MAIN', '10.0000', '0.0000', '0.0000', '10.0000', '0.0000', null, [], null, $currencyCode);
    }

    public function testCodesOf_ListsEachForeignCurrencyOnceAndNeverTheBase(): void
    {
        $this->assertSame(['EUR', 'USD'], ForeignAmounts::codesOf([$this->document(1, null), $this->document(2, 'USD'), $this->document(3, 'EUR'), $this->document(4, 'USD')]));
        $this->assertSame([], ForeignAmounts::codesOf([$this->document(1, null)]));
    }

    public function testGuard_LeavesTheChecksAloneWhenEveryAmountIsInTheBaseCurrency(): void
    {
        $ties = [new TieCheck('Net', '1.0000', '2.0000')];

        $this->assertSame($ties, ForeignAmounts::guard($ties, [null, '']));
        $this->assertSame($ties, ForeignAmounts::guard($ties, []));
    }

    public function testGuard_RejectsEveryCheckByNameAndSaysWhichCurrencies(): void
    {
        $guarded = ForeignAmounts::guard([new TieCheck('Net', '1.0000', '1.0000'), new TieCheck('Lines', '1.0000', '2.0000')], [null, 'USD', 'EUR', 'USD']);

        $this->assertSame(['Net', 'Lines'], array_map(static fn (TieCheck $tie): string => $tie->label, $guarded));

        foreach ($guarded as $tie) {
            $this->assertTrue($tie->isRefused());
            $this->assertFalse($tie->ties());
            $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['EUR, USD']), $tie->refusal);
        }
    }
}
