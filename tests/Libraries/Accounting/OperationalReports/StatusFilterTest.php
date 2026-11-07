<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\InvoiceFigures;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ReportDocument;
use App\Libraries\Accounting\OperationalReports\StatusFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The states a sales report can be limited to: an invoice is paid, partly paid or unpaid by what was settled
 * on it whatever was taken back, overdue by its due date, and a return is told by its document.
 */
class StatusFilterTest extends CIUnitTestCase
{
    private function invoice(string $total, string $paid, string $applied = '0', string $returned = '0', string $due = '2026-10-01'): ReportDocument
    {
        return new ReportDocument(1, DocumentKind::Invoice, '2026-09-01', 1, 'HQ', 1, 'A', 1, 'B', '1', $total, '0', '0', '0', '0', $total, $total, '0', $total, '0', new InvoiceFigures(1, '2026-09-01', SALE_TYPE_INVOICE, $total, $paid, $applied, $returned, $due));
    }

    private function note(DocumentKind $kind): ReportDocument
    {
        return new ReportDocument(2, $kind, '2026-09-01', 1, 'HQ', 1, 'A', 1, 'B', '2', '-10', '0', '0', '0', '0', '-10', '-10', '0', '-10');
    }

    public function testMatches_TellsAnInvoiceByWhatWasSettledOnIt(): void
    {
        $today = '2026-10-27';

        $this->assertTrue(StatusFilter::Paid->matches($this->invoice('100', '60', '40'), $today));
        $this->assertTrue(StatusFilter::Paid->matches($this->invoice('100', '120'), $today));
        $this->assertTrue(StatusFilter::Partial->matches($this->invoice('100', '30'), $today));
        $this->assertTrue(StatusFilter::Unpaid->matches($this->invoice('100', '0'), $today));
        $this->assertFalse(StatusFilter::Paid->matches($this->invoice('100', '30'), $today));
        $this->assertFalse(StatusFilter::Unpaid->matches($this->invoice('100', '30'), $today));
    }

    public function testMatches_KeepsAPaidInvoiceWithAReturnBothPaidAndPaidWithAReturn(): void
    {
        $document = $this->invoice('100', '100', '0', '30');

        $this->assertTrue(StatusFilter::Paid->matches($document, '2026-10-27'));
        $this->assertTrue(StatusFilter::PaidRefund->matches($document, '2026-10-27'));
        $this->assertFalse(StatusFilter::UnpaidRefund->matches($document, '2026-10-27'));
        $this->assertTrue(StatusFilter::UnpaidRefund->matches($this->invoice('100', '0', '0', '30'), '2026-10-27'));
        $this->assertFalse(StatusFilter::PaidRefund->matches($this->invoice('100', '100'), '2026-10-27'));
    }

    public function testMatches_TellsOverdueByTheDueDateAndWhatIsStillOpen(): void
    {
        $this->assertTrue(StatusFilter::Overdue->matches($this->invoice('100', '10', '0', '0', '2026-10-01'), '2026-10-27'));
        $this->assertFalse(StatusFilter::Overdue->matches($this->invoice('100', '10', '0', '0', '2026-11-30'), '2026-10-27'));
        $this->assertFalse(StatusFilter::Overdue->matches($this->invoice('100', '100', '0', '0', '2026-10-01'), '2026-10-27'));
    }

    public function testMatches_TellsAReturnByTheDocumentItWasIssuedAs(): void
    {
        $this->assertTrue(StatusFilter::CreditNote->matches($this->note(DocumentKind::CreditNote), '2026-10-27'));
        $this->assertFalse(StatusFilter::CreditNote->matches($this->note(DocumentKind::RefundInvoice), '2026-10-27'));
        $this->assertTrue(StatusFilter::Refund->matches($this->note(DocumentKind::RefundInvoice), '2026-10-27'));
        $this->assertFalse(StatusFilter::Paid->matches($this->note(DocumentKind::CreditNote), '2026-10-27'));
        $this->assertFalse(StatusFilter::Unpaid->matches($this->note(DocumentKind::CreditNote), '2026-10-27'));
    }

    public function testAdmits_AdmitsEveryDocumentWithNoFilterAndAnyOfSeveral(): void
    {
        $paid = $this->invoice('100', '100');

        $this->assertTrue(StatusFilter::admits([], $paid, '2026-10-27'));
        $this->assertTrue(StatusFilter::admits([StatusFilter::Unpaid, StatusFilter::Paid], $paid, '2026-10-27'));
        $this->assertFalse(StatusFilter::admits([StatusFilter::Unpaid, StatusFilter::Partial], $paid, '2026-10-27'));
    }

    public function testFromRequest_KeepsTheKnownStatesOnceAndDropsTheRest(): void
    {
        $this->assertSame([StatusFilter::Paid, StatusFilter::Refund], StatusFilter::fromRequest(['paid', 'bogus', 'paid', ['x'], 'refund']));
        $this->assertSame([], StatusFilter::fromRequest('paid'));
        $this->assertSame([], StatusFilter::fromRequest(null));
    }
}
