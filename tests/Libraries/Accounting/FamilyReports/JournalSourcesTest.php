<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalSources;
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

    public function testLabel_NamesAKnownSourceAndKeepsTheCodeOfAnUnknownOne(): void
    {
        $this->assertSame(lang('Accounting.source_expense'), JournalSources::label('expense'));
        $this->assertSame('made_up', JournalSources::label('made_up'));
    }
}
