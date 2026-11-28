<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Sets the journal the engine would post beside the one the code of the document posts, and writes
 * down every difference. Two journals agree when their heads have the same date, source, kind and
 * branch, and they have the same lines, each line taken as its account, its side, its amount, its
 * cost center and its amount in a foreign currency with that currency: the description and the order
 * of the lines do not count.
 */
final class ShadowComparison
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * What one journal has that the other has not: its head as "date|source|kind|branch" when the heads
     * differ, and its lines as "account|side|amount|branch|center|foreign amount|currency|auto_tax".
     *
     * @return array{only_legacy: list<string>, only_engine: list<string>}
     */
    public static function differences(?JournalDraft $legacy, ?JournalDraft $engine): array
    {
        $left  = self::lines($legacy);
        $right = self::lines($engine);

        $differences = ['only_legacy' => self::minus($left, $right), 'only_engine' => self::minus($right, $left)];

        if ($legacy !== null && $engine !== null && self::head($legacy) !== self::head($engine)) {
            array_unshift($differences['only_legacy'], 'head ' . self::head($legacy));
            array_unshift($differences['only_engine'], 'head ' . self::head($engine));
        }

        return $differences;
    }

    private static function head(JournalDraft $draft): string
    {
        return implode('|', [$draft->date, $draft->sourceType, $draft->entryType->value, (string) $draft->branchId]);
    }

    /**
     * Writes the difference down when there is one. Returns whether the two agree.
     */
    public function compare(string $operation, ?JournalDraft $legacy, ?JournalDraft $engine, ?int $ruleSetId, string $sourceType, ?int $sourceId, int $revision, ?Throwable $failure = null): bool
    {
        $differences = self::differences($legacy, $engine);

        if ($failure === null && $differences['only_legacy'] === [] && $differences['only_engine'] === []) {
            return true;
        }

        if ($failure !== null) {
            $differences['engine_failed'] = ($failure instanceof PostingException ? $failure->error->value : $failure::class) . ': ' . $failure->getMessage();
        }

        $written = $this->db->table('accounting_posting_shadow_diffs')->insert([
            'company_id'   => CompanyContext::id(),
            'operation'    => $operation,
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'revision'     => $revision,
            'branch_id'    => $legacy?->branchId ?? $engine?->branchId,
            'rule_set_id'  => $ruleSetId,
            'legacy_lines' => json_encode(self::lines($legacy)),
            'engine_lines' => json_encode(self::lines($engine)),
            'differences'  => json_encode($differences, JSON_UNESCAPED_UNICODE),
        ]);

        if ($written === false) {
            throw new RuntimeException('The difference could not be written: ' . ($this->db->error()['message'] ?? ''));
        }

        return false;
    }

    /**
     * @return list<array{diff_id: int, operation: string, source_type: string, source_id: ?int, revision: int, branch_id: ?int, differences: array<string, mixed>, created_at: string}>
     */
    public function recorded(?string $operation = null, int $limit = 100): array
    {
        $builder = $this->db->table('accounting_posting_shadow_diffs')->where('company_id', CompanyContext::id());

        if ($operation !== null) {
            $builder->where('operation', $operation);
        }

        return array_map(static fn (object $row): array => [
            'diff_id'     => (int) $row->diff_id,
            'operation'   => (string) $row->operation,
            'source_type' => (string) $row->source_type,
            'source_id'   => $row->source_id === null ? null : (int) $row->source_id,
            'revision'    => (int) $row->revision,
            'branch_id'   => $row->branch_id === null ? null : (int) $row->branch_id,
            'differences' => (array) json_decode((string) $row->differences, true),
            'created_at'  => (string) $row->created_at,
        ], $builder->orderBy('diff_id', 'DESC')->limit($limit)->get()->getResult());
    }

    /**
     * @return list<string>
     */
    private static function lines(?JournalDraft $draft): array
    {
        if ($draft === null) {
            return [];
        }

        $lines = [];

        foreach ($draft->lines as $line) {
            $debit   = bccomp($line->debit, '0', self::SCALE) > 0;
            $lines[] = implode('|', [$line->accountId, $debit ? 'debit' : 'credit', bcadd($debit ? $line->debit : $line->credit, '0', self::SCALE), (string) $draft->branchId, (string) $line->costCenterId, $line->foreignAmount === null ? '' : bcadd($line->foreignAmount, '0', self::SCALE), (string) $line->currencyCode, $line->autoTax ? 'auto_tax' : '']);
        }

        sort($lines);

        return $lines;
    }

    /**
     * @param list<string> $from
     * @param list<string> $take
     *
     * @return list<string>
     */
    private static function minus(array $from, array $take): array
    {
        foreach ($take as $line) {
            $at = array_search($line, $from, true);

            if ($at !== false) {
                unset($from[$at]);
            }
        }

        return array_values($from);
    }
}
