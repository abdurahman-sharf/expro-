<?php

namespace App\Libraries\Accounting\FamilyReports;

use Closure;
use Generator;
use LogicException;

/**
 * The rows of a report as they are read, block after block, with the total of the report, the checks and
 * the notes that are known once the last row was read. Nothing is kept but the page that is asked for, so
 * a period of any size costs time and not memory. The rows can be read once.
 */
final class ReportStream
{
    private bool $read = false;

    /**
     * @param list<ReportColumn>                                                             $columns
     * @param Closure(): Generator<StreamEvent>                                              $events
     * @param Closure(): array{grand: ?ReportRow, ties: list<TieCheck>, notes: list<string>} $summary Called once every event was read.
     * @param Closure(): int                                                                 $count   How many rows the events hold, without reading them.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        private readonly Closure $events,
        private readonly Closure $summary,
        private readonly Closure $count,
    ) {
    }

    public function rowCount(): int
    {
        return ($this->count)();
    }

    public function pages(int $size): int
    {
        return max(1, (int) ceil($this->rowCount() / max(1, $size)));
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function events(): Generator
    {
        if ($this->read) {
            throw new LogicException('The rows of a stream are read once.');
        }

        $this->read = true;

        yield from ($this->events)();
    }

    /**
     * @return array{grand: ?ReportRow, ties: list<TieCheck>, notes: list<string>}
     */
    public function summary(): array
    {
        return ($this->summary)();
    }

    public function table(): ReportTable
    {
        $blocks = [];
        $rows   = [];
        $title  = null;

        foreach ($this->events() as $event) {
            if ($event->kind === StreamEvent::OPEN) {
                $title = $event->title;
                $rows  = [];
            } elseif ($event->kind === StreamEvent::ROW) {
                $rows[] = $event->row;
            } else {
                $blocks[] = new ReportBlock($title, $rows, $event->row);
                $rows     = [];
            }
        }

        $summary = $this->summary();

        return new ReportTable($this->title, $this->columns, $blocks, $summary['grand'], $summary['ties'], $summary['notes']);
    }

    /**
     * What ReportTable::page() gives for the same rows: a block keeps its title on every page it has a row
     * on and its total on the page of its last row, and the grand total is on every page.
     */
    public function page(int $page, int $size): ReportTable
    {
        $size   = max(1, $size);
        $first  = (max(1, $page) - 1) * $size;
        $last   = $first + $size;
        $seen   = 0;
        $blocks = [];
        $title  = null;
        $kept   = [];
        $count  = 0;

        foreach ($this->events() as $event) {
            if ($event->kind === StreamEvent::OPEN) {
                $title = $event->title;
                $kept  = [];
                $count = 0;
            } elseif ($event->kind === StreamEvent::ROW) {
                $index = $seen + $count;

                if ($index >= $first && $index < $last) {
                    $kept[] = $event->row;
                }

                $count++;
            } else {
                $start = max($first - $seen, 0);
                $end   = min($last - $seen, $count);

                if ($end > $start || ($count === 0 && $seen >= $first && $seen < $last)) {
                    $blocks[] = new ReportBlock($title, $kept, $end >= $count ? $event->row : null);
                }

                $seen += $count;
                $kept  = [];
                $count = 0;
            }
        }

        $summary = $this->summary();

        return new ReportTable($this->title, $this->columns, $blocks, $summary['grand'], $summary['ties'], $summary['notes']);
    }

    public function empty(): ReportTable
    {
        return new ReportTable($this->title, $this->columns, []);
    }
}
