<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The drafts of the VAT returns kept for each period, worked out again each time they are saved until one is
 * approved, with what was typed by hand and the review of what the books do not classify kept beside them. A return
 * is approved only once the books of the company are closed through the last day of its period, so nothing can be
 * posted inside an approved period without reopening it, which the closing records with its reason. Approving works
 * the return out again from the books as they stand and refuses it while something in it is not reviewed (the sales
 * without tax saved before the Saudi rules, documents whose lines do not match their journals, a report that does
 * not tie), so no estimate is ever approved. Periods do not overlap an approved one. An approved return is never
 * changed. Nothing is sent to ZATCA (D-16).
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
     * @param array<string, string>                               $review See VatReturnDraft::build().
     *
     * @throws AccountingException When the period cannot be read, overlaps an approved return or was approved.
     */
    public function saveDraft(string $from, string $to, array $manual, ?int $employeeId, array $review = []): int
    {
        $this->assertPeriod($from, $to);

        $row = $this->db->table('vat_returns')->where(['company_id' => CompanyContext::id(), 'period_start' => $from, 'period_end' => $to])->get()->getRow();

        if ($row !== null && $row->status === self::APPROVED) {
            throw new AccountingException(AccountingError::VatReturnApproved, 'The VAT return of the period was approved and does not change.', ['return_id' => (int) $row->return_id]);
        }

        $this->assertNoOverlap($from, $to, $row === null ? null : (int) $row->return_id);

        $data = $this->worked($from, $to, $manual, $review);

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

        $this->assertNoOverlap((string) $row->period_start, (string) $row->period_end, $returnId);

        $stored = json_decode((string) ($row->manual ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        $draft  = (new VatReturnDraft($this->db))->build((string) $row->period_start, (string) $row->period_end, (array) ($stored['boxes'] ?? []), (array) ($stored['review'] ?? []));

        if ($draft['blockers'] !== []) {
            throw new AccountingException(AccountingError::VatReturnUnreviewed, 'The VAT return holds what was not reviewed: ' . implode(', ', $draft['blockers']) . '.', ['return_id' => $returnId, 'name' => implode(', ', $draft['blockers'])]);
        }

        $this->db->table('vat_returns')->where('return_id', $returnId)->update([
            'boxes'       => json_encode($draft['boxes'], JSON_THROW_ON_ERROR),
            'estimated'   => $draft['unclassified'],
            'status'      => self::APPROVED,
            'approved_by' => $employeeId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function find(int $returnId): ?object
    {
        return $this->db->table('vat_returns')->where(['company_id' => CompanyContext::id(), 'return_id' => $returnId])->get()->getRow();
    }

    /**
     * @param array<string, array{amount?: string, vat?: string}> $manual
     * @param array<string, string>                               $review
     *
     * @return array<string, string>
     */
    private function worked(string $from, string $to, array $manual, array $review): array
    {
        $draft = (new VatReturnDraft($this->db))->build($from, $to, $manual, $review);

        return [
            'boxes'     => json_encode($draft['boxes'], JSON_THROW_ON_ERROR),
            'manual'    => json_encode(['boxes' => $manual, 'review' => $review], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'estimated' => $draft['unclassified'],
        ];
    }

    /**
     * @throws AccountingException
     */
    private function assertPeriod(string $from, string $to): void
    {
        foreach ([$from, $to] as $day) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

            if ($date === false || $date->format('Y-m-d') !== $day) {
                throw new AccountingException(AccountingError::VatReturnPeriodInvalid, 'The period of the return is not two days written as YYYY-MM-DD.', ['from' => $from, 'to' => $to]);
            }
        }

        if (strcmp($from, $to) > 0) {
            throw new AccountingException(AccountingError::VatReturnPeriodInvalid, 'The period of the return ends before it begins.', ['from' => $from, 'to' => $to]);
        }
    }

    /**
     * @throws AccountingException When a day of the period is in the period of an approved return of another period.
     */
    private function assertNoOverlap(string $from, string $to, ?int $returnId): void
    {
        $builder = $this->db->table('vat_returns')
            ->where(['company_id' => CompanyContext::id(), 'status' => self::APPROVED])
            ->where('period_start <=', $to)
            ->where('period_end >=', $from);

        if ($returnId !== null) {
            $builder->where('return_id !=', $returnId);
        }

        $other = $builder->get()->getRow();

        if ($other !== null) {
            throw new AccountingException(AccountingError::VatReturnOverlaps, 'The period overlaps the period of an approved return.', ['from' => (string) $other->period_start, 'to' => (string) $other->period_end]);
        }
    }
}
