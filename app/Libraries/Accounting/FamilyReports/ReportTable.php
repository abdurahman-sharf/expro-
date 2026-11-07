<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class ReportTable
{
    /**
     * @param list<ReportColumn> $columns
     * @param list<ReportBlock>  $blocks
     * @param list<TieCheck>     $ties
     * @param list<string>       $notes   The sentences that say what the report does not show or counts another way, already in the language of the reader.
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $blocks,
        public ?ReportRow $grand = null,
        public array $ties = [],
        public array $notes = [],
    ) {
    }

    public function rowCount(): int
    {
        return array_sum(array_map(static fn (ReportBlock $block): int => count($block->rows), $this->blocks));
    }

    public function ties(): bool
    {
        foreach ($this->ties as $tie) {
            if (! $tie->ties()) {
                return false;
            }
        }

        return true;
    }

    /**
     * One page of the rows, in the order of the blocks. A block keeps its title on every page it
     * has a row on, and its total on the page of its last row; the grand total is on every page.
     */
    public function page(int $page, int $size): self
    {
        $size   = max(1, $size);
        $first  = (max(1, $page) - 1) * $size;
        $last   = $first + $size;
        $seen   = 0;
        $blocks = [];

        foreach ($this->blocks as $block) {
            $count = count($block->rows);
            $start = max($first - $seen, 0);
            $end   = min($last - $seen, $count);

            if ($end > $start || ($count === 0 && $seen >= $first && $seen < $last)) {
                $blocks[] = new ReportBlock($block->title, array_slice($block->rows, $start, max($end - $start, 0)), $end >= $count ? $block->total : null);
            }

            $seen += $count;
        }

        return new self($this->title, $this->columns, $blocks, $this->grand, $this->ties, $this->notes);
    }

    public function pages(int $size): int
    {
        return max(1, (int) ceil($this->rowCount() / max(1, $size)));
    }
}
