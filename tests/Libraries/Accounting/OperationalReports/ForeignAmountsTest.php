<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ForeignDocument;
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
        return new ReportDocument($id, DocumentKind::Invoice, '2026-10-01', 1, 'MAIN', null, '', null, '', 'POS ' . $id, '10.0000', '0.0000', '0.0000', '0.0000', '0.0000', '10.0000', '10.0000', '0.0000', '10.0000', currencyCode: $currencyCode);
    }

    public function testDocumentsOf_NamesEachForeignDocumentOnceAndNeverOneInTheBase(): void
    {
        $documents = [$this->document(1, null), $this->document(2, 'USD'), $this->document(3, 'EUR'), $this->document(2, 'USD')];

        $this->assertSame(['POS 2 (USD)', 'POS 3 (EUR)'], array_map(static fn (ForeignDocument $document): string => $document->label(), ForeignAmounts::documentsOf($documents)));
        $this->assertSame([], ForeignAmounts::documentsOf([$this->document(1, null)]));
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
            $this->assertSame(['POS 2 (USD)'], $tie->refusalArguments);
        }
    }
}
