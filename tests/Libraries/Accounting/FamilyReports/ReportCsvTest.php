<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Export\StreamedCsvResponse;
use App\Libraries\Accounting\FamilyReports\ColumnKind;
use App\Libraries\Accounting\FamilyReports\ReportBlock;
use App\Libraries\Accounting\FamilyReports\ReportColumn;
use App\Libraries\Accounting\FamilyReports\ReportCsv;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportStream;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\FamilyReports\StreamEvent;
use App\Libraries\Accounting\FamilyReports\TieCheck;
use CodeIgniter\Test\CIUnitTestCase;
use Generator;

/**
 * The CSV file of a report: the same rows from the table that holds them and from the stream that reads
 * them, written as they are read and at the same bytes as when the whole file is held.
 *
 * @internal
 */
final class ReportCsvTest extends CIUnitTestCase
{
    private const EXPECTED = "\xEF\xBB\xBF" . <<<'CSV'
        Title
        "Scope note"
        Name,Amount
        First
        a,1.0000
        '='b,2.0000
        Total,3.0000
        Grand,3.0000
        Ties,3.0000,3.0000,0.0000
        Refused,,,Why
        "A note"

        CSV;

    /**
     * @return list<ReportBlock>
     */
    private function blocks(): array
    {
        return [new ReportBlock('First', [new ReportRow(['name' => 'a', 'amount' => '1.0000']), new ReportRow(['name' => "='b", 'amount' => '2.0000'])], new ReportRow(['name' => 'Total', 'amount' => '3.0000'], null, true))];
    }

    private function columns(): array
    {
        return [new ReportColumn('name', 'Name'), new ReportColumn('amount', 'Amount', ColumnKind::Amount)];
    }

    private function ties(): array
    {
        return [new TieCheck('Ties', '3.0000', '3.0000'), TieCheck::refused('Refused', 'Why')];
    }

    private function table(): ReportTable
    {
        return new ReportTable('Title', $this->columns(), $this->blocks(), new ReportRow(['name' => 'Grand', 'amount' => '3.0000'], null, true), $this->ties(), ['A note']);
    }

    private function stream(): ReportStream
    {
        $blocks = $this->blocks();

        return new ReportStream(
            'Title',
            $this->columns(),
            static function () use ($blocks): Generator {
                foreach ($blocks as $block) {
                    yield StreamEvent::open($block->title);

                    foreach ($block->rows as $row) {
                        yield StreamEvent::row($row);
                    }

                    yield StreamEvent::close($block->total);
                }
            },
            fn (): array => ['grand' => new ReportRow(['name' => 'Grand', 'amount' => '3.0000'], null, true), 'ties' => $this->ties(), 'notes' => ['A note']],
            static fn (): int => 2,
        );
    }

    public function testRows_GivesTheSameRowsFromATableAndFromAStream(): void
    {
        $this->assertSame(
            iterator_to_array(ReportCsv::rows($this->table(), 'Scope note'), false),
            iterator_to_array(ReportCsv::rows($this->stream(), 'Scope note'), false),
        );
    }

    public function testCsv_WritesTheRowsOfATableAndOfAStreamAtTheSameBytes(): void
    {
        $fromTable  = new StreamedCsvResponse('table.csv', fn (): iterable => ReportCsv::rows($this->table(), 'Scope note'));
        $fromStream = new StreamedCsvResponse('stream.csv', fn (): iterable => ReportCsv::rows($this->stream(), 'Scope note'));

        $this->assertSame(self::EXPECTED, $fromTable->csv());
        $this->assertSame(self::EXPECTED, $fromStream->csv());
        $this->assertSame(self::EXPECTED, $fromStream->getBody());
    }

    public function testRows_LeavesOutTheLineOfTheScopeWhenThereIsNone(): void
    {
        $rows = iterator_to_array(ReportCsv::rows($this->table(), null), false);

        $this->assertSame(['Title'], $rows[0]);
        $this->assertSame(['Name', 'Amount'], $rows[1]);
    }

    public function testSendBody_WritesTheSameBytesAsTheTextOfTheFile(): void
    {
        $response = new StreamedCsvResponse('stream.csv', fn (): iterable => ReportCsv::rows($this->stream(), 'Scope note'));

        ob_start();
        $response->sendBody();

        $this->assertSame(self::EXPECTED, ob_get_clean());
    }

    public function testCsv_RefusesAFileOfMoreRowsThanTheLimitWithoutHoldingIt(): void
    {
        $read     = 0;
        $response = new StreamedCsvResponse('long.csv', static function () use (&$read): iterable {
            for ($row = 0; $row < 1_000_000; $row++) {
                $read++;

                yield ['row', (string) $row];
            }
        });

        try {
            $response->csv(100);
            $this->fail('A file of a million rows was accepted with a limit of a hundred.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportFileTooLarge, $refusal->error);
            $this->assertSame(100, $refusal->context['max']);
        }

        $this->assertSame(101, $read);
    }

    public function testResponse_IsACsvAttachment(): void
    {
        $response = new StreamedCsvResponse('journals_2025-01-01.csv', fn (): iterable => []);

        $this->assertSame('text/csv; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="journals_2025-01-01.csv"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
