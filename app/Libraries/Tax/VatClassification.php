<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\Organization\CompanyContext;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The VAT classes of the company, their rates by date and the class of each item. A classified item, sold while
 * the Saudi mode applies, is charged one tax at its class's rate on the day of the supply, and the line keeps what
 * it was sold under; an item nobody classified is charged as it always was. A rate is never changed once it is in
 * place: a new one is added from a later day and the open one is closed the day before, so every past sale keeps
 * the rate it was sold at. The classes are managed with the taxes module, and an item is classified from its own
 * form with the items module; nothing here asks for a permission the screens did not already ask for.
 */
final class VatClassification implements LineVatSource
{
    use RunsInTransaction;

    public const CATEGORIES        = ['S', 'Z', 'E', 'O'];
    public const TAXES_PERMISSION  = 'taxes';
    public const ITEMS_PERMISSION  = 'items';
    private const CODE_PATTERN     = '/^[A-Z0-9][A-Z0-9\-]{0,29}$/';
    private const NAME_MAX         = 100;
    private const TAX_NAME_MAX     = 255;
    private const MAX_STANDARD_PCT = '100';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private CompanyTaxProfile $profile;
    private AuditLog $audit;

    /**
     * @var Closure(): string The day of the server, as Y-m-d.
     */
    private Closure $today;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?CompanyTaxProfile $profile = null, ?Closure $today = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->guard   = $guard ?? new AccountingGuard();
        $this->profile = $profile ?? new CompanyTaxProfile($this->db, $this->guard);
        $this->audit   = new AuditLog($this->db);
        $this->today   = $today ?? static fn (): string => date('Y-m-d');
    }

    /**
     * Whether the tables of the classification are in place, so that a screen reached before the migration ran
     * works as it did before it.
     */
    public function isInstalled(): bool
    {
        return $this->db->tableExists($this->db->prefixTable('item_vat_classes'), false);
    }

    public function appliesNow(): bool
    {
        return $this->profile->isSaudiMode();
    }

    /**
     * The classes of the company with the rate in force on a day, active ones first.
     *
     * @return list<object>
     */
    public function classes(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $rows = $this->db->table('vat_classes')->where('company_id', CompanyContext::id())->orderBy('active', 'DESC')->orderBy('code')->get()->getResult();

        foreach ($rows as $row) {
            $row->current_rate = $this->rateOn((int) $row->class_id, $date);
            $row->rates        = $this->ratesOf((int) $row->class_id);
            $row->items        = $this->db->table('item_vat_classes')->where('class_id', (int) $row->class_id)->countAllResults();
        }

        return $rows;
    }

    public function find(int $classId): ?object
    {
        return $this->db->table('vat_classes')->where(['company_id' => CompanyContext::id(), 'class_id' => $classId])->get()->getRow();
    }

    /**
     * @return list<object>
     */
    public function ratesOf(int $classId): array
    {
        return $this->db->table('vat_class_rates')->where('class_id', $classId)->orderBy('valid_from')->get()->getResult();
    }

    public function rateOn(int $classId, string $date): ?string
    {
        $row = $this->db->table('vat_class_rates')
            ->where('class_id', $classId)
            ->where('valid_from <=', $date)
            ->groupStart()->where('valid_to IS NULL')->orWhere('valid_to >=', $date)->groupEnd()
            ->orderBy('valid_from', 'DESC')
            ->get(1)
            ->getRow();

        return $row === null ? null : (string) $row->percent;
    }

    public function classOf(int $itemId): ?object
    {
        return $this->db->table('item_vat_classes AS ic')
            ->select('vc.*')
            ->join('vat_classes AS vc', 'vc.class_id = ic.class_id')
            ->where(['ic.item_id' => $itemId, 'vc.company_id' => CompanyContext::id()])
            ->get()
            ->getRow();
    }

    public function lineVat(int $itemId, string $date): ?LineVat
    {
        $class = $this->classOf($itemId);

        if ($class === null) {
            return null;
        }

        $percent = $this->rateOn((int) $class->class_id, $date);

        if ($percent === null) {
            throw new VatClassificationException(VatClassificationError::NoRateOnDate, 'The class of the item has no rate on that day.', ['name' => (string) $class->name, 'date' => $date]);
        }

        return new LineVat((int) $class->class_id, (string) $class->category, $percent, $class->exemption_code, $class->exemption_text, (string) $class->tax_name);
    }

    /**
     * What a line of a stored sale was sold under: that very line, or, for a return whose lines are numbered on
     * their own, the first line of the item in the sale it takes the goods back from.
     */
    public function soldUnder(int $saleId, int $itemId, int $line): ?LineVat
    {
        $row = $this->db->table('sales_items_vat AS siv')
            ->select('siv.*, vc.tax_name')
            ->join('vat_classes AS vc', 'vc.class_id = siv.class_id', 'left')
            ->where(['siv.sale_id' => $saleId, 'siv.item_id' => $itemId])
            ->orderBy('siv.line = ' . $line, 'DESC', false)
            ->orderBy('siv.line')
            ->get(1)
            ->getRow();

        return $row === null ? null : new LineVat($row->class_id === null ? null : (int) $row->class_id, (string) $row->category, (string) $row->percent, $row->exemption_code, $row->exemption_text, (string) ($row->tax_name ?? ''));
    }

    /**
     * What every line of a sale was sold under, by item and line.
     *
     * @return array<string, LineVat>
     */
    public function linesOfSale(int $saleId): array
    {
        $lines = [];

        foreach ($this->db->table('sales_items_vat')->where('sale_id', $saleId)->get()->getResult() as $row) {
            $lines[$row->item_id . ':' . $row->line] = new LineVat($row->class_id === null ? null : (int) $row->class_id, (string) $row->category, (string) $row->percent, $row->exemption_code, $row->exemption_text, '');
        }

        return $lines;
    }

    /**
     * Keeps what a line of a sale was sold under, in the transaction of the sale.
     *
     * @throws AccountingException
     */
    public function recordLine(int $saleId, int $itemId, int $line, LineVat $vat, ?VatLineResult $amounts = null): void
    {
        $row = [
            'sale_id'        => $saleId,
            'item_id'        => $itemId,
            'line'           => $line,
            'class_id'       => $vat->classId,
            'category'       => $vat->category,
            'percent'        => $vat->percent,
            'exemption_code' => $vat->exemptionCode,
            'exemption_text' => $vat->exemptionText,
        ];

        if ($amounts !== null) {
            $row += ['net' => $amounts->net, 'vat' => $amounts->vat, 'gross' => $amounts->gross, 'calc_version' => VatCalculator::VERSION];
        }

        $saved = $this->db->table('sales_items_vat')->replace($row);

        if ($saved === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The VAT class of a line could not be saved.');
        }
    }

    /**
     * Creates a class, or changes the name, the reason, the tax name or whether it is offered of one. The category
     * of a class is never changed, since its rates and the lines sold under it belong to it.
     *
     * @param array<string, mixed> $input code, name, category, exemption_code, exemption_text, tax_name, active; and percent and valid_from for the first rate of a new class.
     *
     * @throws AccountingException|VatClassificationException
     */
    public function saveClass(array $input, ?int $classId, int $employeeId): int
    {
        $this->guard->require(self::TAXES_PERMISSION, $employeeId);

        $existing = $classId === null ? null : $this->find($classId);

        if ($classId !== null && $existing === null) {
            throw new VatClassificationException(VatClassificationError::ClassNotFound, 'The class does not exist.');
        }

        $values = [
            'code'           => strtoupper(trim((string) ($input['code'] ?? $existing?->code ?? ''))),
            'name'           => trim((string) ($input['name'] ?? $existing?->name ?? '')),
            'category'       => $existing === null ? strtoupper(trim((string) ($input['category'] ?? ''))) : (string) $existing->category,
            'exemption_code' => trim((string) ($input['exemption_code'] ?? $existing?->exemption_code ?? '')),
            'exemption_text' => trim((string) ($input['exemption_text'] ?? $existing?->exemption_text ?? '')),
            'tax_name'       => trim((string) ($input['tax_name'] ?? $existing?->tax_name ?? '')),
            'active'         => array_key_exists('active', $input) ? ((bool) $input['active'] ? 1 : 0) : (int) ($existing?->active ?? 1),
        ];

        $this->validateClass($values, $classId);

        if ($classId === null && trim((string) ($input['valid_from'] ?? '')) === '') {
            throw new VatClassificationException(VatClassificationError::DateInvalid, 'A new class starts with its first rate.');
        }

        $row = [...$values, 'exemption_code' => $values['exemption_code'] === '' ? null : $values['exemption_code'], 'exemption_text' => $values['exemption_text'] === '' ? null : $values['exemption_text'], 'updated_by' => $employeeId, 'updated_at' => date('Y-m-d H:i:s')];

        return $this->transactional(function () use ($row, $classId, $existing, $input, $employeeId): int {
            if ($classId === null) {
                $this->db->table('vat_classes')->insert(['company_id' => CompanyContext::id(), ...$row]);
                $classId = (int) $this->db->insertID();

                $this->insertRate($classId, $row['category'], (string) ($input['percent'] ?? ''), (string) $input['valid_from'], $employeeId);
            } else {
                $this->db->table('vat_classes')->where('class_id', $classId)->update($row);
            }

            $this->audit->write('vat_classification', $classId, $existing === null ? 'class_created' : 'class_updated', $employeeId, JournalOrigin::User, ['before' => $existing === null ? null : (array) $existing, 'after' => $row]);

            return $classId;
        });
    }

    /**
     * Adds a rate from a day after the start of the last one, and closes the open rate the day before.
     *
     * @throws AccountingException|VatClassificationException
     */
    public function addRate(int $classId, string $percent, string $validFrom, int $employeeId): void
    {
        $this->guard->require(self::TAXES_PERMISSION, $employeeId);

        $class = $this->find($classId);

        if ($class === null) {
            throw new VatClassificationException(VatClassificationError::ClassNotFound, 'The class does not exist.');
        }

        $this->transactional(function () use ($classId, $class, $percent, $validFrom, $employeeId): void {
            $this->insertRate($classId, (string) $class->category, $percent, $validFrom, $employeeId);
        });
    }

    /**
     * Gives items a class, or takes their class away with null.
     *
     * @param list<int> $itemIds
     *
     * @return int How many items changed.
     *
     * @throws AccountingException|VatClassificationException
     */
    public function classifyItems(array $itemIds, ?int $classId, int $employeeId, string $permission = self::TAXES_PERMISSION): int
    {
        $this->guard->require($permission, $employeeId);

        if ($classId !== null && $this->find($classId) === null) {
            throw new VatClassificationException(VatClassificationError::ClassNotFound, 'The class does not exist.');
        }

        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return 0;
        }

        return $this->transactional(function () use ($itemIds, $classId, $employeeId): int {
            $before  = [];
            $changed = 0;

            foreach ($this->db->table('item_vat_classes')->whereIn('item_id', $itemIds)->get()->getResult() as $row) {
                $before[(int) $row->item_id] = (int) $row->class_id;
            }

            foreach ($itemIds as $itemId) {
                $previous = $before[$itemId] ?? null;

                if ($previous === $classId) {
                    continue;
                }

                $classId === null
                    ? $this->db->table('item_vat_classes')->where('item_id', $itemId)->delete()
                    : $this->db->table('item_vat_classes')->replace(['item_id' => $itemId, 'class_id' => $classId, 'updated_by' => $employeeId, 'updated_at' => date('Y-m-d H:i:s')]);

                $this->audit->write('vat_classification', $itemId, 'item_classified', $employeeId, JournalOrigin::User, ['before' => $previous, 'after' => $classId]);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * Classifies the items no one classified that carry exactly one tax above nothing, which are the items the
     * standard can take as they are; an item with several taxes is left for a person to decide.
     *
     * @throws AccountingException|VatClassificationException
     */
    public function classifyUnclassifiedWithOneTax(int $classId, int $employeeId): int
    {
        return $this->classifyItems($this->unclassifiedItemIds(1), $classId, $employeeId);
    }

    /**
     * @throws AccountingException|VatClassificationException
     */
    public function classifyByCategory(string $category, int $classId, int $employeeId): int
    {
        $ids = array_map(static fn (object $row): int => (int) $row->item_id, $this->db->table('items')->select('item_id')->where(['deleted' => 0, 'category' => $category])->get()->getResult());

        return $this->classifyItems($ids, $classId, $employeeId);
    }

    public function unclassifiedCount(): int
    {
        return count($this->unclassifiedItemIds(null));
    }

    /**
     * How many items no one classified carry more than one tax above nothing, which the standard refuses.
     */
    public function ambiguousCount(): int
    {
        return count(array_filter($this->taxCounts(), static fn (int $count): bool => $count > 1));
    }

    /**
     * @param int|null $taxes Only the items with this many taxes above nothing, or every unclassified item with null.
     *
     * @return list<int>
     */
    private function unclassifiedItemIds(?int $taxes): array
    {
        $counts = $this->taxCounts();
        $ids    = [];

        foreach ($this->db->table('items AS i')->select('i.item_id')->join('item_vat_classes AS ic', 'ic.item_id = i.item_id', 'left')->where('i.deleted', 0)->where('ic.item_id IS NULL')->get()->getResult() as $row) {
            if ($taxes === null || ($counts[(int) $row->item_id] ?? 0) === $taxes) {
                $ids[] = (int) $row->item_id;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, int> How many taxes above nothing each unclassified item carries.
     */
    private function taxCounts(): array
    {
        $counts = [];

        foreach ($this->db->table('items_taxes AS it')->select('it.item_id, COUNT(*) AS taxes')->join('item_vat_classes AS ic', 'ic.item_id = it.item_id', 'left')->where('it.percent >', 0)->where('ic.item_id IS NULL')->groupBy('it.item_id')->get()->getResult() as $row) {
            $counts[(int) $row->item_id] = (int) $row->taxes;
        }

        return $counts;
    }

    /**
     * @throws AccountingException|VatClassificationException
     */
    private function insertRate(int $classId, string $category, string $percent, string $validFrom, int $employeeId): void
    {
        $percent = trim($percent);
        $from    = $this->date($validFrom);

        if (preg_match('/^\d{1,3}(\.\d{1,3})?$/', $percent) !== 1 || ($category === 'S' ? (bccomp($percent, '0', 3) <= 0 || bccomp($percent, self::MAX_STANDARD_PCT, 3) > 0) : bccomp($percent, '0', 3) !== 0)) {
            throw new VatClassificationException(VatClassificationError::RateInvalid, 'A standard class has a rate above nothing, and a class without tax has none.');
        }

        $classes = $this->db->prefixTable('vat_classes');

        LockedRead::row($this->db, "SELECT class_id FROM {$classes} WHERE class_id = ? FOR UPDATE", [$classId]);

        $last  = $this->db->table('vat_class_rates')->where('class_id', $classId)->orderBy('valid_from', 'DESC')->get(1)->getRow();
        $today = ($this->today)();

        if ($last === null && strcmp($from, $today) > 0) {
            throw new VatClassificationException(VatClassificationError::FirstRateAfterToday, 'A new class starts with a rate in force today.');
        }

        if ($last !== null && strcmp($from, $today) < 0) {
            throw new VatClassificationException(VatClassificationError::RateBeforeToday, 'A rate added to a class starts today or later.');
        }

        if ($last !== null && strcmp($from, (string) $last->valid_from) <= 0) {
            throw new VatClassificationException(VatClassificationError::RateNotAfterLast, 'A new rate starts after the start of the last one.', ['date' => (string) $last->valid_from]);
        }

        if ($last !== null && ($last->valid_to === null || strcmp((string) $last->valid_to, $from) >= 0)) {
            $this->db->table('vat_class_rates')->where('rate_id', (int) $last->rate_id)->update(['valid_to' => (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d')]);
        }

        $this->db->table('vat_class_rates')->insert(['class_id' => $classId, 'percent' => $percent, 'valid_from' => $from, 'created_by' => $employeeId]);
        $this->audit->write('vat_classification', $classId, 'rate_added', $employeeId, JournalOrigin::User, ['percent' => $percent, 'valid_from' => $from, 'closed' => $last === null ? null : (int) $last->rate_id]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @throws VatClassificationException
     */
    private function validateClass(array $values, ?int $classId): void
    {
        if (preg_match(self::CODE_PATTERN, $values['code']) !== 1) {
            throw new VatClassificationException(VatClassificationError::CodeInvalid, 'The code holds capital letters, digits and dashes.');
        }

        $taken = $this->db->table('vat_classes')->where(['company_id' => CompanyContext::id(), 'code' => $values['code']]);

        if ($classId !== null) {
            $taken->where('class_id !=', $classId);
        }

        if ($taken->countAllResults() > 0) {
            throw new VatClassificationException(VatClassificationError::CodeTaken, 'Another class has this code.');
        }

        if ($values['name'] === '' || mb_strlen($values['name']) > self::NAME_MAX) {
            throw new VatClassificationException(VatClassificationError::NameMissing, 'A class has a name.');
        }

        if ($values['tax_name'] === '' || mb_strlen($values['tax_name']) > self::TAX_NAME_MAX) {
            throw new VatClassificationException(VatClassificationError::TaxNameMissing, 'A class names the tax it is charged as.');
        }

        if (! in_array($values['category'], self::CATEGORIES, true)) {
            throw new VatClassificationException(VatClassificationError::CategoryInvalid, 'The category is S, Z, E or O.');
        }

        if ($values['category'] === 'S') {
            if ($values['exemption_code'] !== '' || $values['exemption_text'] !== '') {
                throw new VatClassificationException(VatClassificationError::ExemptionNotAllowed, 'A standard class has no reason for an exemption.');
            }

            return;
        }

        if (! in_array($values['exemption_code'], EInvoiceSettings::EXEMPTION_CODES[$values['category']], true)) {
            throw new VatClassificationException(VatClassificationError::ExemptionCodeInvalid, 'The reason code does not belong to the category.');
        }

        if ($values['exemption_text'] === '' || ! EInvoiceRules::within($values['exemption_text'], EInvoiceRules::NOTE_MAX)) {
            throw new VatClassificationException(VatClassificationError::ExemptionTextMissing, 'A class without tax gives the reason in words.');
        }
    }

    /**
     * @throws VatClassificationException
     */
    private function date(string $value): string
    {
        $value  = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new VatClassificationException(VatClassificationError::DateInvalid, 'The date is not a date.');
        }

        return $value;
    }
}
