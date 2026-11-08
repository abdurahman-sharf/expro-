<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\EvidenceRows;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EvidenceRowsTest extends CIUnitTestCase
{
    public function testNumber_PutsThePrefixOnANumberOfTheAutomaticSeries(): void
    {
        $this->assertSame('A-17', EvidenceRows::number('auto', '17'));
    }

    public function testNumber_LeavesANumberOfAnotherSeriesAsItIs(): void
    {
        $this->assertSame('17', EvidenceRows::number('manual', '17'));
        $this->assertSame('17', EvidenceRows::number('', '17'));
    }

    public function testNumber_LeavesAnEmptyNumberEmpty(): void
    {
        $this->assertSame('', EvidenceRows::number('auto', ''));
    }

    public function testJournal_NamesTheJournalWithItsNumberAndDate(): void
    {
        $row = (object) ['journal_id' => '42', 'series' => 'auto', 'number' => '9', 'journal_date' => '2026-10-31'];

        $this->assertSame(
            ['type' => 'journal', 'journal_id' => 42, 'number' => 'A-9', 'date' => '2026-10-31'],
            EvidenceRows::journal($row),
        );
    }

    public function testJournal_AddsWhatTheRuleAsksFor(): void
    {
        $row = (object) ['journal_id' => 7, 'series' => null, 'number' => null, 'journal_date' => '2026-10-01'];

        $this->assertSame(
            ['type' => 'journal', 'journal_id' => 7, 'number' => '', 'date' => '2026-10-01', 'debit' => '12.5000'],
            EvidenceRows::journal($row, ['debit' => '12.5000']),
        );
    }

    public function testText_JoinsTheRowsWithABar(): void
    {
        $text = EvidenceRows::text([
            ['type' => 'journal', 'journal_id' => 1],
            ['type' => 'history', 'uses' => 3],
        ]);

        $this->assertSame('type=journal journal_id=1 | type=history uses=3', $text);
    }

    public function testText_LeavesOutACellWithNoValue(): void
    {
        $this->assertSame('type=absent found=0', EvidenceRows::text([['type' => 'absent', 'account' => null, 'note' => '', 'found' => 0]]));
    }

    public function testText_GivesNothingForNoRows(): void
    {
        $this->assertSame('', EvidenceRows::text([]));
    }
}
