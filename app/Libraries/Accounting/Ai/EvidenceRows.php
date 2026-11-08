<?php

namespace App\Libraries\Accounting\Ai;

/**
 * The rows a finding or a proposal shows as its evidence: each one is flat, holds only text and numbers, and
 * names the journal it came from, so that the screen can link to it and the file can write it.
 */
final class EvidenceRows
{
    /**
     * @param array<string, scalar|null> $extra
     *
     * @return array<string, scalar|null>
     */
    public static function journal(object $row, array $extra = []): array
    {
        return [
            'type'       => 'journal',
            'journal_id' => (int) $row->journal_id,
            'number'     => self::number((string) ($row->series ?? ''), (string) ($row->number ?? '')),
            'date'       => (string) $row->journal_date,
        ] + $extra;
    }

    public static function number(string $series, string $number): string
    {
        return $series === 'auto' && $number !== '' ? 'A-' . $number : $number;
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     */
    public static function text(array $rows): string
    {
        return implode(' | ', array_map(static function (array $row): string {
            $cells = [];

            foreach ($row as $key => $value) {
                if ($value !== null && $value !== '') {
                    $cells[] = $key . '=' . $value;
                }
            }

            return implode(' ', $cells);
        }, $rows));
    }
}
