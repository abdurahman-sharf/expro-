<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tells the fixed discounts of purchase lines that were saved as an amount for the whole line from
 * those saved as an amount for every unit, and marks the first kind as {@see FIXED_LINE}.
 *
 * A fixed discount on a line of one unit means the same either way, so only lines of several
 * units can be told apart. What the books posted settles a purchase that was posted: the total
 * the supplier was owed fits exactly one reading of the stored line. A purchase that never
 * reached the books is legacy when it was saved before the discount became an amount for every
 * unit existed, and otherwise is left for the operator to decide, because nothing in it says
 * which way it was saved. Nothing is marked while a line is left undecided.
 */
final class LegacyPurchaseDiscount
{
    public const RESOLUTION_KEY = 'purchase_discount_legacy_resolution';
    public const NOT_BEFORE     = '2026-10-25 00:00:00';
    public const ALL            = 'all';
    public const NONE           = 'none';
    public const BEFORE         = 'before';

    private BaseConnection $db;
    private ReceivingLoader $loader;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->loader = new ReceivingLoader($this->db);
    }

    /**
     * @return list<LegacyDiscountFinding>
     */
    public function findings(): array
    {
        $rows = $this->db->table('receivings_items l')
            ->select('l.receiving_id, l.line, l.item_id, l.quantity_purchased * l.receiving_quantity AS units, l.discount, r.receiving_time', false)
            ->join('receivings r', 'r.receiving_id = l.receiving_id')
            ->where('l.discount_type', FIXED)
            ->where('l.discount >', 0)
            ->where('ABS(l.quantity_purchased * l.receiving_quantity) <> 1', null, false)
            ->orderBy('l.receiving_id', 'ASC')
            ->orderBy('l.line', 'ASC')
            ->get()
            ->getResult();

        $verdicts = [];
        $findings = [];

        foreach ($rows as $row) {
            $id = (int) $row->receiving_id;

            $verdicts[$id] ??= $this->verdictOf($id, (string) $row->receiving_time);

            [$verdict, $reason] = $verdicts[$id];

            $findings[] = new LegacyDiscountFinding(
                receivingId: $id,
                line: (int) $row->line,
                itemId: (int) $row->item_id,
                units: (string) $row->units,
                discount: (string) $row->discount,
                savedAt: (string) $row->receiving_time,
                verdict: $verdict,
                reason: $reason,
            );
        }

        return $findings;
    }

    /**
     * What the books show about when the discount began to be an amount for every unit: the latest
     * purchase they show was saved for the whole line, and the earliest they show was saved for
     * every unit. It began between the two, and an undecided line outside them is not in doubt. When
     * the two overlap the books show both kinds in the same period, and no moment settles it.
     *
     * @param list<LegacyDiscountFinding> $findings
     *
     * @return array{wholeLine: ?string, everyUnit: ?string, overlap: bool}
     */
    public static function window(array $findings): array
    {
        $window = ['wholeLine' => null, 'everyUnit' => null, 'overlap' => false];

        foreach ($findings as $finding) {
            if ($finding->verdict === LegacyDiscountVerdict::LegacyByBooks && ($window['wholeLine'] === null || $finding->savedAt > $window['wholeLine'])) {
                $window['wholeLine'] = $finding->savedAt;
            }

            if ($finding->verdict === LegacyDiscountVerdict::PerUnitByBooks && ($window['everyUnit'] === null || $finding->savedAt < $window['everyUnit'])) {
                $window['everyUnit'] = $finding->savedAt;
            }
        }

        $window['overlap'] = $window['wholeLine'] !== null && $window['everyUnit'] !== null && $window['wholeLine'] >= $window['everyUnit'];

        return $window;
    }

    /**
     * @return array{mark: list<LegacyDiscountFinding>, keep: list<LegacyDiscountFinding>, blocked: list<LegacyDiscountFinding>}
     */
    public function plan(): array
    {
        $resolution = $this->resolution();
        $plan       = ['mark' => [], 'keep' => [], 'blocked' => []];

        foreach ($this->findings() as $finding) {
            $bucket = match ($finding->verdict) {
                LegacyDiscountVerdict::LegacyByBooks, LegacyDiscountVerdict::LegacyByDate => 'mark',
                LegacyDiscountVerdict::PerUnitByBooks => 'keep',
                LegacyDiscountVerdict::Undetermined   => $this->decided($finding, $resolution),
            };

            $plan[$bucket][] = $finding;
        }

        return $plan;
    }

    /**
     * @return int The lines marked.
     *
     * @throws LegacyDiscountBlocked when a line is left undecided, before anything is marked
     */
    public function apply(): int
    {
        $plan = $this->plan();

        if ($plan['blocked'] !== []) {
            throw new LegacyDiscountBlocked($plan['blocked']);
        }

        foreach ($plan['mark'] as $finding) {
            $this->db->table('receivings_items')
                ->where(['receiving_id' => $finding->receivingId, 'line' => $finding->line, 'discount_type' => FIXED])
                ->update(['discount_type' => FIXED_LINE]);
        }

        return count($plan['mark']);
    }

    /**
     * @return array{kind: string, before: ?string}|null
     */
    public function resolution(): ?array
    {
        $value = $this->db->table('app_config')->select('value')->where('key', self::RESOLUTION_KEY)->get()->getRow('value');

        if (! is_string($value) || $value === '') {
            return null;
        }

        if ($value === self::ALL || $value === self::NONE) {
            return ['kind' => $value, 'before' => null];
        }

        $moment = str_starts_with($value, self::BEFORE . ':') ? substr($value, strlen(self::BEFORE) + 1) : '';

        return self::isMoment($moment) ? ['kind' => self::BEFORE, 'before' => $moment] : null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resolve(string $kind, ?string $before = null): void
    {
        $value = match ($kind) {
            self::ALL, self::NONE => $kind,
            self::BEFORE => self::isMoment((string) $before) ? self::BEFORE . ':' . $before : throw new InvalidArgumentException('The moment must be a valid YYYY-MM-DD HH:MM:SS.'),
            default      => throw new InvalidArgumentException('The decision must be all, none or before.'),
        };

        $this->db->query(
            'INSERT INTO `' . $this->db->prefixTable('app_config') . '` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [self::RESOLUTION_KEY, $value],
        );
    }

    public function clearResolution(): void
    {
        $this->db->table('app_config')->where('key', self::RESOLUTION_KEY)->delete();
    }

    /**
     * @param array{kind: string, before: ?string}|null $resolution
     */
    private function decided(LegacyDiscountFinding $finding, ?array $resolution): string
    {
        return match ($resolution['kind'] ?? null) {
            self::ALL    => 'mark',
            self::NONE   => 'keep',
            self::BEFORE => $finding->savedAt < $resolution['before'] ? 'mark' : 'keep',
            default      => 'blocked',
        };
    }

    /**
     * @return array{LegacyDiscountVerdict, string}
     */
    private function verdictOf(int $receivingId, string $savedAt): array
    {
        $amounts = $this->postedAmounts($receivingId);
        $note    = 'it never reached the books';

        if ($amounts !== []) {
            $document = $this->loader->load($receivingId, false);

            if ($document !== null) {
                $legacy  = $this->holds($amounts, $this->total($document, FIXED_LINE));
                $perUnit = $this->holds($amounts, $this->total($document, FIXED));

                if ($legacy && ! $perUnit) {
                    return [LegacyDiscountVerdict::LegacyByBooks, 'the posted journal fits the discount as an amount for the line'];
                }

                if ($perUnit && ! $legacy) {
                    return [LegacyDiscountVerdict::PerUnitByBooks, 'the posted journal fits the discount as an amount for every unit'];
                }

                if (! $legacy) {
                    return [LegacyDiscountVerdict::Undetermined, 'the posted journal fits neither reading of the discount'];
                }

                $note = 'the posted journal fits both readings';
            }
        }

        return $savedAt < self::NOT_BEFORE
            ? [LegacyDiscountVerdict::LegacyByDate, 'saved before the discount could be an amount for every unit']
            : [LegacyDiscountVerdict::Undetermined, "saved after the discount could be an amount for every unit and {$note}"];
    }

    /**
     * @return list<numeric-string>
     */
    private function postedAmounts(int $receivingId): array
    {
        if (! $this->exists('accounting_journals') || ! $this->exists('accounting_journal_lines')) {
            return [];
        }

        $journal = $this->db->table('accounting_journals')
            ->select('journal_id')
            ->where('source_type', ReceivingPostingBuilder::SOURCE_PURCHASE)
            ->where('source_id', $receivingId)
            ->where('entry_type', JournalEntryType::General->value)
            ->where('status', JournalStatus::Posted->value)
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($journal === null) {
            return [];
        }

        $amounts = [];

        foreach ($this->db->table('accounting_journal_lines')->select('debit, credit')->where('journal_id', $journal->journal_id)->get()->getResult() as $line) {
            $amounts[] = bccomp((string) $line->debit, '0', ReceivingAmounts::SCALE) > 0 ? (string) $line->debit : (string) $line->credit;
        }

        return $amounts;
    }

    private function exists(string $table): bool
    {
        return $this->db->tableExists($this->db->prefixTable($table), false);
    }

    /**
     * @param list<numeric-string> $amounts
     * @param numeric-string       $total
     */
    private function holds(array $amounts, string $total): bool
    {
        foreach ($amounts as $amount) {
            if (bccomp($amount, $total, ReceivingAmounts::SCALE) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the supplier is owed when every fixed discount of several units is read as the given type.
     *
     * @return numeric-string
     */
    private function total(ReceivingDocument $document, int $type): string
    {
        $net = '0';
        $tax = '0';

        foreach ($document->lines as $line) {
            $reading = $this->isCandidate($line) ? new ReceivingLine(
                itemId: $line->itemId,
                line: $line->line,
                quantity: $line->quantity,
                unitsPerPack: $line->unitsPerPack,
                unitPrice: $line->unitPrice,
                discount: $line->discount,
                discountType: $type,
                taxPercent: $line->taxPercent,
                locationId: $line->locationId,
                hasStock: $line->hasStock,
                itemCost: $line->itemCost,
                invoiceDiscount: $line->invoiceDiscount,
            ) : $line;

            $net = bcadd($net, ReceivingAmounts::net($reading), ReceivingAmounts::SCALE);
            $tax = bcadd($tax, ReceivingAmounts::tax($reading), ReceivingAmounts::SCALE);
        }

        return SaleAmounts::round(bcadd($net, $tax, ReceivingAmounts::SCALE), 2);
    }

    private function isCandidate(ReceivingLine $line): bool
    {
        return $line->discountType === FIXED
            && bccomp($line->discount, '0', 4) > 0
            && bccomp(ReceivingAmounts::units($line), '1', 3) !== 0;
    }

    private static function isMoment(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }
}
