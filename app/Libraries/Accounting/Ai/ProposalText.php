<?php

namespace App\Libraries\Accounting\Ai;

/**
 * The words of a proposal or a finding, filled from a template of its kind with the figures taken from
 * the books. Nothing here is written by a model: a kind that has no template shows the text that was
 * stored with the record, or its code.
 */
final class ProposalText
{
    /**
     * @param array<string, scalar> $args
     */
    public static function fill(string $part, string $kind, array $args, string $stored = ''): string
    {
        $key  = 'Accounting_ai.' . $part . '_' . $kind;
        $line = lang($key, $args);

        if ($line !== $key) {
            return $line;
        }

        return $stored !== '' ? $stored : $kind;
    }

    /**
     * @return array<string, scalar>
     */
    public static function argsOf(?string $json): array
    {
        $decoded = $json === null || $json === '' ? [] : json_decode($json, true);

        return is_array($decoded) ? array_filter($decoded, 'is_scalar') : [];
    }
}
