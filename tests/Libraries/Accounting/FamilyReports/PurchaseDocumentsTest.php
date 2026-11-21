<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\PurchaseDocuments;
use App\Libraries\Accounting\ReportPeriod;
use Tests\Support\FamilyReportTestCase;

/**
 * The purchases and the returns of a period as one list of documents, each with its lines, what it is
 * worth and what its journal holds.
 *
 * @internal
 */
final class PurchaseDocumentsTest extends FamilyReportTestCase
{
    private PurchaseDocuments $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documents = new PurchaseDocuments();
    }

    public function testRead_GivesTheLinesOfEveryDocumentWithTheRoundingOnTheLastLine(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '3', '33.33', '15'), $this->purchaseLine($item, 1, '1', '10.01', '15')]);

        $documents = $this->documents->read($this->everyBranch(), ReportPeriod::of($this->today(), $this->today()));
        $document  = array_values(array_filter($documents, static fn ($document): bool => $document->receivingId === $id))[0];

        $sum = '0';

        foreach ($document->lines as $line) {
            $sum = bcadd($sum, $line->total, 4);
        }

        $this->assertAmount($document->total, $sum);
        $this->assertAmount($document->total, (string) $document->journalTotal);
    }

    public function testRead_GivesTheLinesOfAPurchaseSavedUnderTheSaudiRulesTheTaxOfTheirRate(): void
    {
        $db      = $this->accountingDb();
        $profile = $db->table('company_tax_profile')->get()->getRowArray();
        $item    = $this->item('0.00');

        try {
            $db->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

            $id = $this->buy([$this->purchaseLine($item, 0, '1', '0.10', '15'), $this->purchaseLine($item, 1, '1', '0.10', '15'), $this->purchaseLine($item, 2, '1', '0.10', '15')]);
        } finally {
            $db->table('company_tax_profile')->update(['ksa_mode' => $profile['ksa_mode'], 'ksa_mode_effective_at' => $profile['ksa_mode_effective_at']]);
        }

        $documents = $this->documents->read($this->everyBranch(), ReportPeriod::of($this->today(), $this->today()));
        $document  = array_values(array_filter($documents, static fn ($document): bool => $document->receivingId === $id))[0];
        $tax       = '0';
        $total     = '0';

        foreach ($document->lines as $line) {
            $tax   = bcadd($tax, $line->tax, 4);
            $total = bcadd($total, $line->total, 4);
        }

        $this->assertAmount('0.05', $document->tax);
        $this->assertAmount($document->tax, $tax, 'the lines add up to the tax of the rate, not to 0.045');
        $this->assertAmount($document->total, $total);
        $this->assertAmount($document->total, (string) $document->journalTotal);
    }

    public function testNet_TakesTheReturnsAndDebitNotesOffTheInvoices(): void
    {
        $this->market();

        $documents = $this->documents->read($this->everyBranch(), ReportPeriod::of($this->today(), $this->today()));

        $this->assertCount(4, $documents);
        $this->assertAmount('395.00', PurchaseDocuments::net($documents));
    }

    public function testBooked_GivesWhatTheJournalsOfTheDocumentsHoldAndLeavesOutADocumentWithoutOne(): void
    {
        $ids    = $this->market();
        $period = ReportPeriod::of($this->today(), $this->today());

        $this->assertAmount('395.00', $this->documents->booked($this->everyBranch(), $period));
        $this->assertSame(0, PurchaseDocuments::unposted($this->documents->read($this->everyBranch(), $period)));

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $ids['other'])->update(['status' => 'reversed']);

        $this->assertAmount('295.00', $this->documents->booked($this->everyBranch(), $period));
        $this->assertSame(1, PurchaseDocuments::unposted($this->documents->read($this->everyBranch(), $period)));
    }

    public function testRead_ReadsNothingForAScopeThatHoldsNoBranch(): void
    {
        $this->market();

        $this->assertSame([], $this->documents->read($this->limitedTo(), ReportPeriod::of($this->today(), $this->today())));
        $this->assertSame('0.0000', $this->documents->booked($this->limitedTo(), ReportPeriod::of($this->today(), $this->today())));
    }
}
