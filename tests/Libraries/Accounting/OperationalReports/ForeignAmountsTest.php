<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ForeignAmounts;
use App\Libraries\Accounting\OperationalReports\ReportDocument;
use App\Libraries\Accounting\OperationalReports\TieCheck;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A check over documents in a foreign currency is refused by name, one over documents in the base
 * currency is left as it is.
 *
 * @internal
 */
final class ForeignAmountsTest extends CIUnitTestCase
{
    private function document(int $id, ?string $currencyCode): ReportDocument
    {
        return new ReportDocument($id, DocumentKind::Invoice, '2026-10-01', 1, 'MAIN', null, '', null, '', (string) $id, '10.0000', '0.0000', '0.0000', '0.0000', '0.0000', '10.0000', '10.0000', '0.0000', '10.0000', currencyCode: $currencyCode);
    }

    public function testCodesOf_ListsEachForeignCurrencyOnceAndNeverTheBase(): void
    {
        $documents = [$this->document(1, null), $this->document(2, 'USD'), $this->document(3, 'EUR'), $this->document(4, 'USD')];

        $this->assertSame(['EUR', 'USD'], ForeignAmounts::codesOf($documents));
        $this->assertSame([], ForeignAmounts::codesOf([$this->document(1, null)]));
    }

    public function testGuard_LeavesTheChecksOfADocumentInTheBaseCurrencyAsTheyAre(): void
    {
        $ties = [new TieCheck('a', '1.0000', '2.0000'), new TieCheck('b', '1.0000', '1.0000')];

        $this->assertSame($ties, ForeignAmounts::guard($ties, [$this->document(1, null)]));
        $this->assertSame($ties, ForeignAmounts::guard($ties, []));
    }

    public function testGuard_RejectsEveryCheckByNameWhenOneDocumentIsInAForeignCurrency(): void
    {
        $guarded = ForeignAmounts::guard([new TieCheck('a', '1.0000', '2.0000'), new TieCheck('b', '1.0000', '1.0000')], [$this->document(1, null), $this->document(2, 'USD')]);

        $this->assertSame(['a', 'b'], array_map(static fn (TieCheck $tie): string => $tie->labelKey, $guarded));

        foreach ($guarded as $tie) {
            $this->assertTrue($tie->isRefused());
            $this->assertSame('Accounting_operational_reports.tie_foreign_currency', $tie->refusal);
            $this->assertSame(['USD'], $tie->refusalArguments);
        }
    }
}
