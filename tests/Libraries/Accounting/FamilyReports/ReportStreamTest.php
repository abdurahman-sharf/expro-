<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ColumnKind;
use App\Libraries\Accounting\FamilyReports\ReportBlock;
use App\Libraries\Accounting\FamilyReports\ReportColumn;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportStream;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\FamilyReports\StreamEvent;
use CodeIgniter\Test\CIUnitTestCase;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rows of a report read one after the other: held, they are the blocks of the table that holds them, and
 * a page of them is the page the table gives.
 *
 * @internal
 */
final class ReportStreamTest extends CIUnitTestCase
{
    /**
     * @return list<ReportBlock>
     */
    private function blocks(): array
    {
        $row = static fn (string $name, string $amount): ReportRow => new ReportRow(['name' => $name, 'amount' => $amount]);

        return [
            new ReportBlock('First', [$row('a', '1.0000'), $row('b', '2.0000'), $row('c', '3.0000')], new ReportRow(['name' => 'Total', 'amount' => '6.0000'], null, true)),
            new ReportBlock('Empty', [], new ReportRow(['name' => 'Total', 'amount' => '0.0000'], null, true)),
            new ReportBlock(null, [$row('d', '4.0000')]),
            new ReportBlock('Last', [$row('e', '5.0000'), $row('f', '6.0000'), $row('g', '7.0000'), $row('h', '8.0000'), $row('i', '9.0000')], new ReportRow(['name' => 'Total', 'amount' => '35.0000'], null, true)),
        ];
    }

    private function streamOf(array $blocks): ReportStream
    {
        $grand = new ReportRow(['name' => 'Grand', 'amount' => '45.0000'], null, true);
        $count = array_sum(array_map(static fn (ReportBlock $block): int => count($block->rows), $blocks));

        return new ReportStream(
            'Title',
            [new ReportColumn('name', 'Name'), new ReportColumn('amount', 'Amount', ColumnKind::Amount)],
            static function () use ($blocks): Generator {
                foreach ($blocks as $block) {
                    yield StreamEvent::open($block->title);

                    foreach ($block->rows as $row) {
                        yield StreamEvent::row($row);
                    }

                    yield StreamEvent::close($block->total);
                }
            },
            static fn (): array => ['grand' => $grand, 'ties' => [], 'notes' => ['note']],
            static fn (): int => $count,
        );
    }

    private function table(array $blocks): ReportTable
    {
        return new ReportTable('Title', [new ReportColumn('name', 'Name'), new ReportColumn('amount', 'Amount', ColumnKind::Amount)], $blocks, new ReportRow(['name' => 'Grand', 'amount' => '45.0000'], null, true), [], ['note']);
    }

    public function testTable_HoldsTheBlocksTheEventsDescribe(): void
    {
        $this->assertEquals($this->table($this->blocks()), $this->streamOf($this->blocks())->table());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function pages(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 7, 100] as $size) {
            foreach ([1, 2, 3, 4, 5, 6, 7, 20] as $page) {
                yield "page {$page} of {$size}" => [$page, $size];
            }
        }
    }

    #[DataProvider('pages')]
    public function testPage_GivesThePageTheTableGives(int $page, int $size): void
    {
        $this->assertEquals($this->table($this->blocks())->page($page, $size), $this->streamOf($this->blocks())->page($page, $size));
    }

    public function testPages_CountsThePagesOfTheRowsTheCountGives(): void
    {
        $stream = $this->streamOf($this->blocks());

        $this->assertSame(9, $stream->rowCount());
        $this->assertSame(3, $stream->pages(4));
        $this->assertSame($this->table($this->blocks())->pages(4), $stream->pages(4));
    }

    public function testEvents_RefusesToBeReadTwice(): void
    {
        $stream = $this->streamOf($this->blocks());

        $stream->table();

        $this->expectException(LogicException::class);

        $stream->table();
    }

    public function testEmpty_KeepsTheTitleAndTheColumnsAndNoRows(): void
    {
        $table = $this->streamOf($this->blocks())->empty();

        $this->assertSame('Title', $table->title);
        $this->assertCount(2, $table->columns);
        $this->assertSame(0, $table->rowCount());
    }
}
