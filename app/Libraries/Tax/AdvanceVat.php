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
 * application is released, that move is reversed. When the credit is paid back in cash, or the advance is cancelled,
 * the tax of what goes back is moved back the same way, a return, which a credit note of the prepayment invoice states.
 * An advance received before carries no VAT and is never changed.
 */
class AdvanceVat
{
    public const SOURCE        = 'customer_advance_vat';
    public const SOURCE_USE    = 'customer_advance_vat_use';
    public const SOURCE_RETURN = 'customer_advance_vat_return';
    public const SOURCES       = [self::SOURCE, self::SOURCE_USE, self::SOURCE_RETURN];

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
    public function received(int $advanceId, ?int $employeeId): bool
    {
        if (! $this->isInstalled() || ! (new CompanyTaxProfile($this->db))->isSaudiMode()) {
            return false;
        }

        $advance = $this->db->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow();

        if ($advance === null) {
            return false;
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
            return false;
        }

        ['taxable' => $taxable, 'vat' => $vat] = self::split($gross, $percent);

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

        return true;
    }

    /**
     * The taxable amount and the VAT of an amount that holds its VAT at the rate, the VAT being the taxable amount
     * times the rate, rounded (BR-KSA-79 and BR-CO-17). Not every amount splits so exactly: one that does not keeps
     * the nearest split, and the cent between them is the rounding of the document that states it (BT-114).
     *
     * @return array{taxable: numeric-string, vat: numeric-string}
     */
    public static function split(string $gross, string $percent): array
    {
        $rate = bcdiv($percent, '100', 10);
        $near = VatCalculator::round(bcdiv(bcmul($gross, '100', 10), bcadd('100', $percent, 10), 10), 2);

        foreach ([$near, bcsub($near, '0.01', 2), bcadd($near, '0.01', 2)] as $taxable) {
            $vat = VatCalculator::round(bcmul($taxable, $rate, 10), 2);

            if (bccomp(bcadd($taxable, $vat, 2), bcadd($gross, '0', 2), 2) === 0) {
                return ['taxable' => $taxable, 'vat' => $vat];
            }
        }

        return ['taxable' => $near, 'vat' => VatCalculator::round(bcmul($near, $rate, 10), 2)];
    }

    /**
     * Gives back the part of the VAT of an advance that an application of its credit takes.
     *
     * @throws AccountingException|PostingException
     */
    public function applied(int $applicationId, int $advanceId, ?int $saleId, string $amount, ?int $employeeId): void
    {
        $part = $this->partOf($advanceId, $amount);

        if ($part === null) {
            return;
        }

        $advance = $this->db->table('customer_advances')->select('branch_id')->where('advance_id', $advanceId)->get()->getRow();

        $this->db->table('customer_advance_vat_uses')->insert([
            'application_id' => $applicationId,
            'advance_id'     => $advanceId,
            'sale_id'        => $saleId,
            'gross'          => $part['gross'],
            'taxable'        => $part['taxable'],
            'vat'            => $part['vat'],
        ]);

        $this->post(self::SOURCE_USE, $applicationId, $this->openDay($this->dayOf($saleId), (int) $advance->branch_id), (int) $advance->branch_id, RoutingKey::TaxOutput, RoutingKey::TaxAdvances, $part['vat'], 'VAT of advance #' . $advanceId . ' taken by an invoice', $employeeId);
    }

    /**
     * Gives back the VAT of the part of an advance that goes back to the customer: paid back by a refund, or, with
     * no refund, what was left of it when it was cancelled. Returns the return, which a credit note of the
     * prepayment invoice states, or null when the advance carries no VAT or none of it is left.
     *
     * @throws AccountingException|PostingException
     */
    public function returned(int $advanceId, string $amount, ?int $refundId, string $date, ?int $employeeId): ?int
    {
        $part = $this->partOf($advanceId, $amount);

        if ($part === null) {
            return null;
        }

        $advance = $this->db->table('customer_advances')->select('branch_id')->where('advance_id', $advanceId)->get()->getRow();

        $this->db->table('customer_advance_vat_returns')->insert([
            'advance_id' => $advanceId,
            'refund_id'  => $refundId,
            'gross'      => $part['gross'],
            'taxable'    => $part['taxable'],
            'vat'        => $part['vat'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $returnId = (int) $this->db->insertID();

        $this->post(self::SOURCE_RETURN, $returnId, $this->openDay($date, (int) $advance->branch_id), (int) $advance->branch_id, RoutingKey::TaxOutput, RoutingKey::TaxAdvances, $part['vat'], 'VAT of advance #' . $advanceId . ' paid back', $employeeId);

        return $returnId;
    }

    /**
     * The returns of the advances a refund paid back.
     *
     * @return list<object>
     */
    public function returnsOfRefund(int $refundId): array
    {
        if (! $this->hasReturns()) {
            return [];
        }

        return $this->db->table('customer_advance_vat_returns')->where(['refund_id' => $refundId, 'released' => 0])->get()->getResult();
    }

    /**
     * Reverses the returns of a refund that is cancelled: the credit is the customer's again, and so is the VAT on it.
     *
     * @throws AccountingException|PostingException
     */
    public function refundCancelled(int $refundId, string $reason, ?int $employeeId): void
    {
        foreach ($this->returnsOfRefund($refundId) as $return) {
            $this->reverse(self::SOURCE_RETURN, (int) $return->return_id, $reason, $employeeId);
            $this->db->table('customer_advance_vat_returns')->where('return_id', (int) $return->return_id)->update(['released' => 1]);
        }
    }

    public function returnOf(int $returnId): ?object
    {
        return $this->hasReturns() ? $this->db->table('customer_advance_vat_returns')->where('return_id', $returnId)->get()->getRow() : null;
    }

    /**
     * The taxable amount and the rate behind a journal of the VAT of advances, or null when nothing stands behind it.
     *
     * @return array{taxable: numeric-string, percent: numeric-string}|null
     */
    public function behind(string $source, int $sourceId): ?array
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $row = match ($source) {
            self::SOURCE        => $this->db->table('customer_advance_vat')->select('taxable, percent')->where('advance_id', $sourceId)->get()->getRow(),
            self::SOURCE_USE    => $this->db->table('customer_advance_vat_uses AS u')->select('u.taxable, v.percent')->join('customer_advance_vat AS v', 'v.advance_id = u.advance_id')->where('u.application_id', $sourceId)->get()->getRow(),
            self::SOURCE_RETURN => $this->hasReturns() ? $this->db->table('customer_advance_vat_returns AS r')->select('r.taxable, v.percent')->join('customer_advance_vat AS v', 'v.advance_id = r.advance_id')->where('r.return_id', $sourceId)->get()->getRow() : null,
            default             => null,
        };

        return $row === null ? null : ['taxable' => bcadd((string) $row->taxable, '0', 2), 'percent' => (string) $row->percent];
    }

    /**
     * What of the VAT of an advance a part of it takes: the rest of the advance when the part is all of what is left,
     * so that the advance is given back to the cent, and its own split otherwise. Null when the advance carries no VAT
     * or nothing of it is left.
     *
     * @return array{gross: numeric-string, taxable: numeric-string, vat: numeric-string, percent: numeric-string}|null
     */
    private function partOf(int $advanceId, string $amount): ?array
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $charged = $this->db->table('customer_advance_vat')->where(['advance_id' => $advanceId, 'cancelled' => 0])->get()->getRow();

        if ($charged === null) {
            return null;
        }

        $taken = $this->taken($advanceId);
        $left  = [
            'gross'   => bcsub((string) $charged->gross, $taken['gross'], 2),
            'taxable' => bcsub((string) $charged->taxable, $taken['taxable'], 2),
            'vat'     => bcsub((string) $charged->vat, $taken['vat'], 2),
        ];

        if (bccomp($left['gross'], '0', 2) <= 0) {
            return null;
        }

        $part = VatCalculator::round($amount, 2);

        if (bccomp($part, $left['gross'], 2) >= 0) {
            return [...$left, 'percent' => (string) $charged->percent];
        }

        return ['gross' => $part, ...self::split($part, (string) $charged->percent), 'percent' => (string) $charged->percent];
    }

    /**
     * What invoices took and refunds paid back of an advance and still stand.
     *
     * @return array{gross: numeric-string, taxable: numeric-string, vat: numeric-string}
     */
    private function taken(int $advanceId): array
    {
        $sum = static fn (?object $row, string $column): string => bcadd((string) ($row->{$column} ?? '0'), '0', 2);
        $sql = 'COALESCE(SUM(gross), 0) AS gross, COALESCE(SUM(taxable), 0) AS taxable, COALESCE(SUM(vat), 0) AS vat';

        $used     = $this->db->table('customer_advance_vat_uses')->select($sql, false)->where(['advance_id' => $advanceId, 'released' => 0])->get()->getRow();
        $returned = $this->hasReturns() ? $this->db->table('customer_advance_vat_returns')->select($sql, false)->where(['advance_id' => $advanceId, 'released' => 0])->get()->getRow() : null;

        return [
            'gross'   => bcadd($sum($used, 'gross'), $sum($returned, 'gross'), 2),
            'taxable' => bcadd($sum($used, 'taxable'), $sum($returned, 'taxable'), 2),
            'vat'     => bcadd($sum($used, 'vat'), $sum($returned, 'vat'), 2),
        ];
    }

    private function hasReturns(): bool
    {
        return $this->db->tableExists($this->db->prefixTable('customer_advance_vat_returns'), false);
    }

    /**
     * The day a journal of the VAT of an advance is dated: the day given, unless its period is closed, and then today.
     */
    private function openDay(string $date, int $branchId): string
    {
        $cutoff = (new PeriodGuard($this->db))->effectiveCutoff($branchId, true);

        return $cutoff !== null && $date <= $cutoff['date'] ? date('Y-m-d') : $date;
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
     * Takes back the VAT an advance was charged when the advance is cancelled; the applications of its credit were
     * released first. While no prepayment invoice states the VAT, its journal is reversed. Once one does, what is
     * left of the advance goes back as a return, which a credit note states; the return is given, or null.
     *
     * @throws AccountingException|PostingException
     */
    public function cancelled(int $advanceId, string $reason, ?int $employeeId, bool $stated = false): ?int
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $charged = $this->db->table('customer_advance_vat')->where(['advance_id' => $advanceId, 'cancelled' => 0])->get()->getRow();

        if ($charged === null) {
            return null;
        }

        $returnId = null;

        if ($stated) {
            $returnId = $this->returned($advanceId, (string) $charged->gross, null, date('Y-m-d'), $employeeId);
        } else {
            $this->reverse(self::SOURCE, $advanceId, $reason, $employeeId);
        }

        $this->db->table('customer_advance_vat')->where('advance_id', $advanceId)->update(['cancelled' => 1]);

        return $returnId;
    }

    /**
     * Whether an invoice whose electronic document was issued takes a part of the advance off what it asks, which the
     * document states as a prepayment and no later change may take away.
     */
    public function takenByIssuedInvoice(int $advanceId): bool
    {
        if (! $this->isInstalled() || ! $this->db->tableExists($this->db->prefixTable('einvoice_documents'), false)) {
            return false;
        }

        return $this->db->table('customer_advance_vat_uses AS u')
            ->join('einvoice_documents AS d', 'd.source_id = u.sale_id')
            ->where(['u.advance_id' => $advanceId, 'u.released' => 0, 'd.source_type' => 'sale'])
            ->countAllResults() > 0;
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
