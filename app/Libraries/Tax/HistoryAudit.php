<?php

namespace App\Libraries\Tax;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the books hold against the Saudi rules, read and never changed (D-11). Each finding counts the records of one
 * kind and names a few of them; a finding marked as a breach is a record the rules forbid, which can only have come
 * from before the issuing of documents or from around it, and is corrected by the owner case by case. The rest
 * describe the history before the rules, which stays as it was.
 */
final class HistoryAudit
{
    public const BREACH  = 'breach';
    public const NOTE    = 'note';
    private const SAMPLE = 10;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{key: string, kind: string, count: int, sample: list<int>}> Empty while the rules were never turned on.
     */
    public function run(): array
    {
        $profile = (new CompanyTaxProfile($this->db))->current();

        if (! $profile->saudiMode || $profile->effectiveAt === null) {
            return [];
        }

        $since     = $profile->effectiveAt;
        $documents = $this->has('einvoice_documents');
        $findings  = [];

        if ($documents) {
            $findings[] = $this->finding('sales_without_document', self::BREACH, $this->issuable($since)->where("NOT EXISTS (SELECT 1 FROM {$this->prefixed('einvoice_documents')} d WHERE d.source_type = 'sale' AND d.source_id = s.sale_id)", null, false));
        }

        $findings[] = $this->finding('returns_without_origin', self::BREACH, $this->issuable($since)->where('s.sale_type', SALE_TYPE_RETURN)->where('s.return_of_sale_id IS NULL', null, false));

        if ($this->has('sales_vat_totals')) {
            $findings[] = $this->finding('sales_without_vat_snapshot', self::BREACH, $this->issuable($since)->where('s.sale_type !=', SALE_TYPE_RETURN)->where("NOT EXISTS (SELECT 1 FROM {$this->prefixed('sales_vat_totals')} t WHERE t.sale_id = s.sale_id)", null, false));
        }

        if ($this->has('customer_advance_vat') && $documents) {
            $findings[] = $this->finding('advances_without_prepayment_invoice', self::BREACH, $this->db->table('customer_advance_vat v')->select('v.advance_id AS id')->join('customer_advances a', 'a.advance_id = v.advance_id')->where('a.company_id', CompanyContext::id())->where('v.cancelled', 0)->where("NOT EXISTS (SELECT 1 FROM {$this->prefixed('einvoice_documents')} d WHERE d.source_type = 'customer_advance' AND d.source_id = v.advance_id)", null, false));
        }

        $findings[] = $this->finding('sales_before_the_rules', self::NOTE, $this->completed()->where('s.sale_time <', $since));

        return $findings;
    }

    /**
     * Whether a finding marked as a breach counts a record.
     *
     * @param list<array{key: string, kind: string, count: int, sample: list<int>}> $findings
     */
    public static function breached(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding['kind'] === self::BREACH && $finding['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function completed(): BaseBuilder
    {
        return $this->db->table('sales s')
            ->select('s.sale_id AS id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN]);
    }

    private function issuable(string $since): BaseBuilder
    {
        return $this->completed()->where('s.sale_time >=', $since);
    }

    /**
     * @return array{key: string, kind: string, count: int, sample: list<int>}
     */
    private function finding(string $key, string $kind, BaseBuilder $builder): array
    {
        $count  = (clone $builder)->countAllResults(false);
        $sample = $count === 0 ? [] : array_map(static fn (object $row): int => (int) $row->id, $builder->orderBy('id', 'ASC')->limit(self::SAMPLE)->get()->getResult());

        return ['key' => $key, 'kind' => $kind, 'count' => $count, 'sample' => $sample];
    }

    private function has(string $table): bool
    {
        return $this->db->tableExists($this->prefixed($table), false);
    }

    private function prefixed(string $table): string
    {
        return $this->db->prefixTable($table);
    }
}
