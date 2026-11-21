<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The VAT of the advances of customers under the Saudi rules (R-04 §9.5). An advance received while the rules apply
 * carries output VAT on the part of it no invoice takes at once, at the standard rate of its day, the amount holding
 * the tax: the tax is moved from the account the VAT of advances waits in to the output VAT, and the account of the
 * customer keeps the whole advance as credit, as it always did. When the credit of the advance pays an invoice, the
 * part of the tax that invoice takes is moved back, since the invoice charges the whole tax of the supply; when the
 * application is released, that move is reversed. An advance received before carries no VAT and is never changed.
 */
class AdvanceVat
{
    public const SOURCE     = 'customer_advance_vat';
    public const SOURCE_USE = 'customer_advance_vat_use';

    private BaseConnection $db;
    private ?bool $installed = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function isInstalled(): bool
    {
        return $this->installed ??= $this->db->tableExists($this->db->prefixTable('customer_advance_vat_uses'), false);
    }

    /**
     * Charges the VAT of an advance just received on what no invoice took of it, when the Saudi rules apply.
     *
     * @throws AccountingException|PostingException
     */
    public function received(int $advanceId, ?int $employeeId): void
    {
        if (! $this->isInstalled() || ! (new CompanyTaxProfile($this->db))->isSaudiMode()) {
            return;
        }

        $advance = $this->db->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow();

        if ($advance === null) {
            return;
        }

        $taken = (string) ($this->db->table('customer_credit_applications')
            ->selectSum('amount', 'taken')
            ->where(['source_type' => CreditSource::CUSTOMER_ADVANCE, 'source_id' => $advanceId])
            ->where('released_at IS NULL', null, false)
            ->get()
            ->getRow()->taken ?? '0');
        $gross   = VatCalculator::round(bcsub((string) $advance->amount, $taken, SaleAmounts::SCALE), 2);
        $percent = $this->standardRate((string) $advance->advance_date);

        if (bccomp($gross, '0', 2) <= 0 || $percent === null || bccomp($percent, '0', 3) <= 0) {
            return;
        }

        $vat     = VatCalculator::round(bcdiv(bcmul($gross, $percent, 10), bcadd('100', $percent, 10), 10), 2);
        $taxable = bcsub($gross, $vat, 2);

        $this->db->table('customer_advance_vat')->insert([
            'advance_id'   => $advanceId,
            'category'     => 'S',
            'percent'      => $percent,
            'gross'        => $gross,
            'taxable'      => $taxable,
            'vat'          => $vat,
            'calc_version' => VatCalculator::VERSION,
        ]);

        $this->post(self::SOURCE, $advanceId, (string) $advance->advance_date, (int) $advance->branch_id, RoutingKey::TaxAdvances, RoutingKey::TaxOutput, $vat, 'VAT on advance #' . $advanceId, $employeeId);
    }

    /**
     * Gives back the part of the VAT of an advance that an application of its credit takes.
     *
     * @throws AccountingException|PostingException
     */
    public function applied(int $applicationId, int $advanceId, ?int $saleId, string $amount, ?int $employeeId): void
    {
        if (! $this->isInstalled()) {
            return;
        }

        $charged = $this->db->table('customer_advance_vat')->where(['advance_id' => $advanceId, 'cancelled' => 0])->get()->getRow();

        if ($charged === null) {
            return;
        }

        $used     = $this->db->table('customer_advance_vat_uses')->select('COALESCE(SUM(gross), 0) AS gross, COALESCE(SUM(vat), 0) AS vat', false)->where(['advance_id' => $advanceId, 'released' => 0])->get()->getRow();
        $leftOver = bcsub((string) $charged->gross, (string) $used->gross, 2);

        if (bccomp($leftOver, '0', 2) <= 0) {
            return;
        }

        $part    = VatCalculator::round($amount, 2);
        $part    = bccomp($part, $leftOver, 2) >= 0 ? $leftOver : $part;
        $vat     = bccomp($part, $leftOver, 2) === 0 ? bcsub((string) $charged->vat, (string) $used->vat, 2) : VatCalculator::round(bcdiv(bcmul($part, (string) $charged->vat, 10), (string) $charged->gross, 10), 2);
        $advance = $this->db->table('customer_advances')->select('branch_id')->where('advance_id', $advanceId)->get()->getRow();

        $this->db->table('customer_advance_vat_uses')->insert([
            'application_id' => $applicationId,
            'advance_id'     => $advanceId,
            'sale_id'        => $saleId,
            'gross'          => $part,
            'taxable'        => bcsub($part, $vat, 2),
            'vat'            => $vat,
        ]);

        $this->post(self::SOURCE_USE, $applicationId, $this->dayOf($saleId), (int) $advance->branch_id, RoutingKey::TaxOutput, RoutingKey::TaxAdvances, $vat, 'VAT of advance #' . $advanceId . ' taken by an invoice', $employeeId);
    }

    /**
     * Reverses what an application gave back of the VAT of an advance, when the application is released.
     *
     * @throws AccountingException|PostingException
     */
    public function released(int $applicationId, ?int $employeeId): void
    {
        if (! $this->isInstalled()) {
            return;
        }

        $use = $this->db->table('customer_advance_vat_uses')->where(['application_id' => $applicationId, 'released' => 0])->get()->getRow();

        if ($use === null) {
            return;
        }

        $this->reverse(self::SOURCE_USE, $applicationId, 'The credit of the advance was released from the invoice', $employeeId);
        $this->db->table('customer_advance_vat_uses')->where('application_id', $applicationId)->update(['released' => 1]);
    }

    /**
     * Reverses the VAT an advance was charged when the advance is cancelled; the applications of its credit were
     * released first.
     *
     * @throws AccountingException|PostingException
     */
    public function cancelled(int $advanceId, string $reason, ?int $employeeId): void
    {
        if (! $this->isInstalled()) {
            return;
        }

        $charged = $this->db->table('customer_advance_vat')->where(['advance_id' => $advanceId, 'cancelled' => 0])->get()->getRow();

        if ($charged === null) {
            return;
        }

        $this->reverse(self::SOURCE, $advanceId, $reason, $employeeId);
        $this->db->table('customer_advance_vat')->where('advance_id', $advanceId)->update(['cancelled' => 1]);
    }

    /**
     * What the VAT of an advance came to, or null when it carries none.
     */
    public function of(int $advanceId): ?object
    {
        return $this->isInstalled() ? $this->db->table('customer_advance_vat')->where('advance_id', $advanceId)->get()->getRow() : null;
    }

    /**
     * The advances an invoice is paid by with the VAT each gives back, for the adjustment of the prepayments its
     * document states.
     *
     * @return list<object> advance_id, gross, taxable, vat, percent, category, advance_date, created_at.
     */
    public function usesOf(int $saleId): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        return $this->db->table('customer_advance_vat_uses AS u')
            ->select('u.advance_id, SUM(u.gross) AS gross, SUM(u.taxable) AS taxable, SUM(u.vat) AS vat, v.percent, v.category, a.advance_date, a.created_at', false)
            ->join('customer_advance_vat AS v', 'v.advance_id = u.advance_id')
            ->join('customer_advances AS a', 'a.advance_id = u.advance_id')
            ->where(['u.sale_id' => $saleId, 'u.released' => 0])
            ->groupBy('u.advance_id, v.percent, v.category, a.advance_date, a.created_at')
            ->orderBy('u.advance_id')
            ->get()
            ->getResult();
    }

    private function standardRate(string $date): ?string
    {
        $classes  = new VatClassification($this->db);
        $standard = $this->db->table('vat_classes')->select('class_id')->where(['code' => 'S', 'category' => 'S'])->get()->getRow();

        return $standard === null ? null : $classes->rateOn((int) $standard->class_id, $date);
    }

    private function dayOf(?int $saleId): string
    {
        $sale = $saleId === null ? null : $this->db->table('sales')->select('sale_time, invoice_date')->where('sale_id', $saleId)->get()->getRow();

        return $sale === null ? date('Y-m-d') : ($sale->invoice_date ?? substr((string) $sale->sale_time, 0, 10));
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function post(string $source, int $sourceId, string $date, int $branchId, RoutingKey $debit, RoutingKey $credit, string $amount, string $description, ?int $employeeId): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        (new PostingService($this->db))->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->account($debit), debit: $amount), new JournalLineDraft($this->account($credit), credit: $amount)],
            sourceType: $source,
            sourceId: $sourceId,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
            entryType: JournalEntryType::General,
        ));
    }

    /**
     * @throws AccountingException
     */
    private function reverse(string $source, int $sourceId, string $reason, ?int $employeeId): void
    {
        $journal = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date, branch_id')
            ->where(['source_type' => $source, 'source_id' => $sourceId, 'status' => JournalStatus::Posted->value])
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($journal === null) {
            return;
        }

        $cutoff = (new PeriodGuard($this->db))->effectiveCutoff((int) $journal->branch_id, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : (string) $journal->journal_date;

        (new JournalService($this->db))->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
    }

    /**
     * @throws PostingException
     */
    private function account(RoutingKey $key): int
    {
        $account = (new RoutingResolver($this->db))->accountFor($key);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but the VAT of an advance cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
