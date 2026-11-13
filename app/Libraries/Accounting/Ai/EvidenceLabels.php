<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\FamilyReports\JournalSources;

/**
 * The words a screen puts in place of the codes an evidence row keeps: the source of a journal, who owns an
 * account, the status of a journal. The row keeps the code, which is what a file and a test read.
 */
final class EvidenceLabels
{
    private const PREFIXES = [
        'entity_type' => 'Accounting_ai.evidence_owner_',
        'status'      => 'Accounting_ai.evidence_status_',
    ];

    public static function value(string $key, string $value): string
    {
        if ($key === 'source') {
            return JournalSources::label($value);
        }

        $prefix = self::PREFIXES[$key] ?? null;

        if ($prefix === null) {
            return $value;
        }

        $line = lang($prefix . $value);

        return $line === $prefix . $value ? $value : $line;
    }
}
