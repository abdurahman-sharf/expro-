<?php

namespace App\Libraries\Accounting\FamilyReports;

use Generator;

/**
 * The rows of the CSV file of a report of the families: its title, whose figures it holds, the headings,
 * each block with its title, rows and total, the grand total, the checks and the notes. A table that is
 * held and a stream that is read give the same rows, so the file is the same whichever the report is.
 */
final class ReportCsv
{
    /**
     * @return Generator<int, list<string>>
     */
    public static function rows(ReportStream|ReportTable $source, ?string $scopeLabel): Generator
    {
        $columns = $source->columns;
        $cells   = static fn (ReportRow $row): array => array_map(static fn (ReportColumn $column): string => $row->cell($column->key), $columns);

        yield [$source->title];

        if ($scopeLabel !== null) {
            yield [$scopeLabel];
        }

        yield array_map(static fn (ReportColumn $column): string => $column->label, $columns);

        foreach (self::events($source) as $event) {
            if ($event->kind === StreamEvent::OPEN) {
                if ($event->title !== null) {
                    yield [$event->title];
                }
            } else {
                if ($event->row !== null) {
                    yield $cells($event->row);
                }
            }
        }

        $summary = $source instanceof ReportTable ? ['grand' => $source->grand, 'ties' => $source->ties, 'notes' => $source->notes] : $source->summary();

        if ($summary['grand'] !== null) {
            yield $cells($summary['grand']);
        }

        foreach ($summary['ties'] as $tie) {
            yield $tie->isRefused() ? [$tie->label, '', '', (string) $tie->refusal] : [$tie->label, $tie->reported, $tie->book, $tie->difference()];
        }

        foreach ($summary['notes'] as $note) {
            yield [$note];
        }
    }

    /**
     * @return Generator<int, StreamEvent>
     */
    private static function events(ReportStream|ReportTable $source): Generator
    {
        if ($source instanceof ReportStream) {
            yield from $source->events();

            return;
        }

        foreach ($source->blocks as $block) {
            yield StreamEvent::open($block->title);

            foreach ($block->rows as $row) {
                yield StreamEvent::row($row);
            }

            yield StreamEvent::close($block->total);
        }
    }
}
