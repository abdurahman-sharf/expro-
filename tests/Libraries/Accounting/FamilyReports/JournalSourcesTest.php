<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalSources;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Stock\StockPermitSources;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The sources of the journals the reports of journals can be narrowed to, each with a name.
 *
 * @internal
 */
final class JournalSourcesTest extends CIUnitTestCase
{
    public function testAll_ListsThoseOfTheBooksWithAName(): void
    {
        $sources = JournalSources::all();

        $this->assertContains('manual', $sources);
        $this->assertContains('purchase', $sources);
        $this->assertContains('expense', $sources);
        $this->assertContains('reversal', $sources);
        $this->assertSame($sources, array_values(array_unique($sources)));

        foreach ($sources as $source) {
            $this->assertNotSame($source, JournalSources::label($source), $source . ' has no name');
        }
    }

    public function testAll_OffersTheJournalsPaymentsGetInTheBranchOfAnotherTreasury(): void
    {
        $sources = JournalSources::all();

        foreach (['sale_payment_branch', 'supplier_payment_branch', 'expense_branch'] as $companion) {
            $this->assertContains($companion, $sources);
        }
    }

    public function testAll_OffersTheSourcesOfTheStockDocumentsAndOfTheExchangeDifferences(): void
    {
        $sources = JournalSources::all();

        foreach ([StockPermitSources::PERMIT, StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT, StockPermitSources::STOCKTAKE, SalePostingBuilder::SOURCE_PAYMENT_FX, ReceivingPostingBuilder::SOURCE_SUPPLIER_FX] as $source) {
            $this->assertContains($source, $sources, $source . ' cannot be filtered on');
        }
    }

    public function testAll_OffersEverySourceThatTheServiceOfJournalsTreatsAsAutomatic(): void
    {
        $offered = JournalSources::all();

        foreach ([...JournalService::TRANSFER_SOURCES, ...JournalService::DOCUMENT_SOURCES, ...JournalService::CREDIT_SOURCES, ...JournalService::FX_SOURCES] as $source) {
            $this->assertContains($source, $offered, $source . ' is automatic and cannot be filtered on');
        }
    }

    public function testLabel_NamesAKnownSourceAndKeepsTheCodeOfAnUnknownOne(): void
    {
        $this->assertSame(lang('Accounting.source_expense'), JournalSources::label('expense'));
        $this->assertSame('made_up', JournalSources::label('made_up'));
    }

    public function testAll_OffersTheSourcesOfTheJournalsOfFixedAssets(): void
    {
        $sources = JournalSources::all();

        foreach (['asset', 'asset_depreciation', 'asset_sale', 'asset_write_off'] as $source) {
            $this->assertContains($source, $sources);
            $this->assertNotSame($source, JournalSources::label($source), $source . ' has no name');
        }
    }
}
