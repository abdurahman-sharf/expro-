<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ForeignAmounts;
use App\Libraries\Accounting\FamilyReports\PurchaseDocument;
use App\Libraries\Accounting\FamilyReports\TieCheck;
use App\Libraries\Accounting\ForeignDocument;
use App\Libraries\Accounting\ReceivingType;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ForeignAmountsTest extends CIUnitTestCase
{
    private function document(int $id, ?string $currencyCode): PurchaseDocument
    {
        return new PurchaseDocument($id, 'RECV ' . $id, '2026-10-01', ReceivingType::Receive, null, '', '', null, '', 1, 'MAIN', '10.0000', '0.0000', '0.0000', '10.0000', '0.0000', null, [], null, $currencyCode);
    }

    private function payment(string $number, ?string $currencyCode): object
    {
        return (object) ['number' => $number, 'currency_code' => $currencyCode];
    }

    public function testDocumentsOf_NamesEachForeignDocumentOnceAndNeverOneInTheBase(): void
    {
        $foreign = ForeignAmounts::documentsOf([$this->document(1, null), $this->document(2, 'USD'), $this->document(3, 'EUR'), $this->document(2, 'USD'), $this->document(4, '')]);

        $this->assertSame(['RECV 2 (USD)', 'RECV 3 (EUR)'], array_map(static fn (ForeignDocument $document): string => $document->label(), $foreign));
        $this->assertSame([], ForeignAmounts::documentsOf([$this->document(1, null)]));
    }

    public function testPaymentsOf_NamesEachForeignPaymentOnceAndNeverOneInTheBase(): void
    {
        $foreign = ForeignAmounts::paymentsOf([$this->payment('SP 1', null), $this->payment('SP 2', 'USD'), $this->payment('SP 2', 'USD'), $this->payment('SP 3', '')]);

        $this->assertSame(['SP 2 (USD)'], array_map(static fn (ForeignDocument $document): string => $document->label(), $foreign));
    }

    public function testGuard_LeavesTheChecksAloneWhenEveryAmountIsInTheBaseCurrency(): void
    {
        $ties = [new TieCheck('Net', '1.0000', '2.0000')];

        $this->assertSame($ties, ForeignAmounts::guard($ties, []));
    }

    public function testGuard_RejectsEveryCheckByNameAndNamesTheForeignDocuments(): void
    {
        $guarded = ForeignAmounts::guard([new TieCheck('Net', '1.0000', '1.0000'), new TieCheck('Lines', '1.0000', '2.0000')], [new ForeignDocument('RECV 7', 'USD'), new ForeignDocument('RECV 9', 'EUR')]);

        $this->assertSame(['Net', 'Lines'], array_map(static fn (TieCheck $tie): string => $tie->label, $guarded));

        foreach ($guarded as $tie) {
            $this->assertTrue($tie->isRefused());
            $this->assertFalse($tie->ties());
            $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['RECV 7 (USD), RECV 9 (EUR)']), $tie->refusal);
            $this->assertStringContainsString('RECV 7 (USD)', (string) $tie->refusal);
        }
    }
}
