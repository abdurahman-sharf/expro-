<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The drafts of the VAT returns kept for each period, worked out again each time they are saved until one is
 * approved. A return is approved only once the books of the company are closed through the last day of its period,
 * so nothing can be posted inside an approved period without reopening it, which the closing records with its
 * reason. An approved return is never changed. Nothing is sent to ZATCA (D-16).
 */
final class VatReturns
{
    public const DRAFT    = 'draft';
    public const APPROVED = 'approved';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<string, array{amount?: string, vat?: string}> $manual
     *
     * @throws AccountingException When the return of the period was approved.
     */
    public function saveDraft(string $from, string $to, array $manual, ?int $employeeId): int
    {
        $draft = (new VatReturnDraft($this->db))->build($from, $to, $manual);
        $row   = $this->db->table('vat_returns')->where(['company_id' => CompanyContext::id(), 'period_start' => $from, 'period_end' => $to])->get()->getRow();
        $data  = ['boxes' => json_encode($draft['boxes'], JSON_THROW_ON_ERROR), 'estimated' => $draft['estimated']];

        if ($row !== null && $row->status === self::APPROVED) {
            throw new AccountingException(AccountingError::VatReturnApproved, 'The VAT return of the period was approved and does not change.', ['return_id' => (int) $row->return_id]);
        }

        if ($row !== null) {
            $this->db->table('vat_returns')->where('return_id', (int) $row->return_id)->update($data);

            return (int) $row->return_id;
        }

        $this->db->table('vat_returns')->insert(['company_id' => CompanyContext::id(), 'period_start' => $from, 'period_end' => $to, 'status' => self::DRAFT, 'created_by' => $employeeId, ...$data]);

        return (int) $this->db->insertID();
    }

    /**
     * @throws AccountingException
     */
    public function approve(int $returnId, ?int $employeeId): void
    {
        $row = $this->db->table('vat_returns')->where(['company_id' => CompanyContext::id(), 'return_id' => $returnId])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::VatReturnNotFound, 'The VAT return does not exist.', ['return_id' => $returnId]);
        }

        if ($row->status === self::APPROVED) {
            throw new AccountingException(AccountingError::VatReturnApproved, 'The VAT return was approved already.', ['return_id' => $returnId]);
        }

        $cutoff = (new ClosedPeriodService($this->db))->cutoff(null);

        if ($cutoff === null || strcmp($cutoff, (string) $row->period_end) < 0) {
            throw new AccountingException(AccountingError::VatReturnPeriodOpen, 'The books are not closed through the last day of the period of the return.', ['date' => (string) $row->period_end]);
        }

        $this->db->table('vat_returns')->where('return_id', $returnId)->update(['status' => self::APPROVED, 'approved_by' => $employeeId, 'approved_at' => date('Y-m-d H:i:s')]);
    }

    public function find(int $returnId): ?object
    {
        return $this->db->table('vat_returns')->where(['company_id' => CompanyContext::id(), 'return_id' => $returnId])->get()->getRow();
    }
}
