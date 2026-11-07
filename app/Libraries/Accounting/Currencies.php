<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The currencies of the books. The base currency of the accounting settings is always there, switched
 * on and worth one, and cannot be switched off. Any other currency is offered on a document only while
 * it is switched on and has a rate. The decimals a currency is kept in (zero, two or three) are fixed
 * once a document, a journal or a treasury has used the currency, because the amounts already written
 * were rounded by them. A currency is never deleted, only switched off.
 */
final class Currencies
{
    use RunsInTransaction;

    public const DOCUMENT_DECIMALS = 2;
    public const USED_BY           = [
        ['sales', 'currency_code'],
        ['sales_payments', 'currency_code'],
        ['receivings', 'currency_code'],
        ['receivings_payments', 'currency_code'],
        ['expenses', 'currency_code'],
        ['revenues', 'currency_code'],
        ['treasuries', 'currency_code'],
        ['treasury_transfers', 'from_currency'],
        ['treasury_transfers', 'to_currency'],
        ['accounting_journal_lines', 'currency_code'],
        ['accounting_journals', 'currency_code'],
    ];
    private const CODE_PATTERN = '/^[A-Z]{3}$/';
    private const NAME_MAX     = 100;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;
    private AccountingSettings $settings;
    private CurrencyRates $rates;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->rates    = new CurrencyRates($this->db, $this->guard, $this->branches);
    }

    public function baseCode(): string
    {
        return $this->settings->baseCurrency();
    }

    public function isBase(string $code): bool
    {
        $code = $this->normalize($code);

        return $code !== '' && $code === $this->baseCode();
    }

    /**
     * Puts the row of the base currency in when there is none, and keeps it switched on. It reads the
     * base from the settings and does nothing while there is none.
     */
    public function ensureBase(): void
    {
        $base = $this->baseCode();

        if ($base === '') {
            return;
        }

        $row = $this->db->table('accounting_currencies')->where('currency_code', $base)->get()->getRow();

        if ($row === null) {
            [$english, $arabic, $decimals] = CurrencyCatalog::CURRENCIES[$base] ?? [$base, $base, 2];

            $this->db->table('accounting_currencies')->ignore(true)->insert(['currency_code' => $base, 'name_ar' => $arabic, 'name_en' => $english, 'decimals' => $decimals, 'active' => 1]);

            return;
        }

        if ((int) $row->active === 0) {
            $this->db->table('accounting_currencies')->where('currency_code', $base)->update(['active' => 1]);
        }
    }

    /**
     * @return list<Currency> The base currency first, then the others by code.
     */
    public function all(bool $activeOnly = false): array
    {
        $this->ensureBase();

        $builder = $this->db->table('accounting_currencies')->orderBy('currency_code', 'ASC');

        if ($activeOnly) {
            $builder->where('active', 1);
        }

        $currencies = array_map(fn (object $row): Currency => $this->currencyOf($row), $builder->get()->getResult());

        usort($currencies, fn (Currency $a, Currency $b): int => [! $this->isBase($a->code), $a->code] <=> [! $this->isBase($b->code), $b->code]);

        return $currencies;
    }

    public function find(string $code): ?Currency
    {
        $this->ensureBase();

        $row = $this->db->table('accounting_currencies')->where('currency_code', $this->normalize($code))->get()->getRow();

        return $row === null ? null : $this->currencyOf($row);
    }

    /**
     * The decimals a currency is kept in. A code the table does not know is kept in two, as every amount
     * of the books has been.
     */
    public function decimalsOf(string $code): int
    {
        return $this->find($code)?->decimals ?? 2;
    }

    public function baseDecimals(): int
    {
        return $this->decimalsOf($this->baseCode());
    }

    /**
     * A currency a document may be in: it exists, is switched on and is not the base.
     *
     * @throws AccountingException
     */
    public function requireForeign(string $code): Currency
    {
        $currency = preg_match(self::CODE_PATTERN, $code) === 1 ? $this->find($code) : null;

        if ($currency === null) {
            throw new AccountingException(AccountingError::CurrencyNotFound, "Currency {$code} does not exist.", ['code' => $code]);
        }

        if ($this->isBase($code)) {
            throw new AccountingException(AccountingError::CurrencyIsBase, 'The base currency is the currency of a document that has none.', ['code' => $code]);
        }

        if (! $currency->active) {
            throw new AccountingException(AccountingError::CurrencyInactive, "Currency {$code} is switched off.", ['code' => $code]);
        }

        return $currency;
    }

    /**
     * The currency of a document dated on a day: null for the base currency, and otherwise the code with
     * the rate of the latest day on or before the date of the document. A rate typed by hand takes the
     * place of the one of the table, and changing money by hand needs the permission to manage currencies. A currency
     * of three decimals is refused: the columns that hold the prices, the discounts and the payments of documents keep two.
     *
     * @throws AccountingException
     */
    public function documentCurrency(?string $code, string $date, ?string $typedRate = null, ?int $employeeId = null): ?DocumentCurrency
    {
        if ($code === null || $code === '' || $this->isBase($code)) {
            return null;
        }

        $currency = $this->requireForeign($code);

        if ($currency->decimals > self::DOCUMENT_DECIMALS) {
            throw new AccountingException(AccountingError::ForeignDecimalsUnsupported, "A document in {$code} is not supported yet: the prices and the payments of documents are kept in two decimals.", ['code' => $code]);
        }

        if ($typedRate !== null && trim($typedRate) !== '') {
            $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);

            return new DocumentCurrency($currency->code, $this->rates->normalize($typedRate), $currency->decimals);
        }

        $rate = $this->rates->rateOn($code, $date);

        if ($rate === null) {
            throw new AccountingException(AccountingError::RateMissing, "Currency {$code} has no rate on or before {$date}.", ['code' => $code, 'date' => $date]);
        }

        return new DocumentCurrency($currency->code, $rate, $currency->decimals);
    }

    /**
     * The currency a stored document carries: the code and the rate it was captured at.
     */
    public function stored(?string $code, ?string $rate): ?DocumentCurrency
    {
        if ($code === null || $code === '' || $rate === null || $this->isBase($code)) {
            return null;
        }

        return new DocumentCurrency($code, bcadd($rate, '0', 8), $this->decimalsOf($code));
    }

    /**
     * @param array{code?: mixed, name_ar?: mixed, name_en?: mixed, decimals?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId = null): string
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $code     = $this->code($input['code'] ?? '');
        $nameAr   = $this->name($input['name_ar'] ?? '');
        $nameEn   = $this->name($input['name_en'] ?? '');
        $decimals = $this->decimals($input['decimals'] ?? CurrencyCatalog::decimalsOf($code));

        return $this->transactional(function () use ($code, $nameAr, $nameEn, $decimals, $employeeId): string {
            if ($this->db->table('accounting_currencies')->where('currency_code', $code)->countAllResults() > 0) {
                throw new AccountingException(AccountingError::CurrencyCodeTaken, "Currency {$code} exists already.", ['code' => $code]);
            }

            $this->db->table('accounting_currencies')->insert(['currency_code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'decimals' => $decimals, 'active' => 1]);
            $this->audit->write('currency', null, 'created', $employeeId, JournalOrigin::User, ['code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'decimals' => $decimals]);

            return $code;
        });
    }

    /**
     * @param array{name_ar?: mixed, name_en?: mixed, decimals?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(string $code, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $code = $this->normalize($code);

        $this->transactional(function () use ($code, $input, $employeeId): void {
            $row     = $this->lock($code);
            $changes = [];

            foreach (['name_ar', 'name_en'] as $field) {
                if (array_key_exists($field, $input)) {
                    $name = $this->name($input[$field]);

                    if ($name !== $row->{$field}) {
                        $changes[$field] = $name;
                    }
                }
            }

            if (array_key_exists('decimals', $input)) {
                $decimals = $this->decimals($input['decimals']);

                if ($decimals !== (int) $row->decimals) {
                    if ($this->inUse($code)) {
                        throw new AccountingException(AccountingError::CurrencyInUse, "Currency {$code} has been used, so its decimals are fixed.", ['code' => $code]);
                    }

                    $changes['decimals'] = $decimals;
                }
            }

            if ($changes === []) {
                return;
            }

            $this->db->table('accounting_currencies')->where('currency_code', $code)->update($changes);
            $this->audit->write('currency', null, 'updated', $employeeId, JournalOrigin::User, ['code' => $code, 'before' => array_intersect_key((array) $row, $changes), 'after' => $changes]);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function setActive(string $code, bool $active, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $code = $this->normalize($code);

        $this->transactional(function () use ($code, $active, $employeeId): void {
            $row = $this->lock($code);

            if (! $active && $this->isBase($code)) {
                throw new AccountingException(AccountingError::CurrencyIsBase, 'The base currency cannot be switched off.', ['code' => $code]);
            }

            if ((int) $row->active === (int) $active) {
                return;
            }

            $this->db->table('accounting_currencies')->where('currency_code', $code)->update(['active' => (int) $active]);
            $this->audit->write('currency', null, $active ? 'activated' : 'deactivated', $employeeId, JournalOrigin::User, ['code' => $code]);
        });
    }

    /**
     * Whether a document, a payment, a journal or a treasury carries the currency.
     */
    public function inUse(string $code): bool
    {
        $code = $this->normalize($code);

        foreach (self::USED_BY as [$table, $column]) {
            if ($this->db->table($table)->where($column, $code)->limit(1)->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    public function rates(): CurrencyRates
    {
        return $this->rates;
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function currencyOf(object $row): Currency
    {
        return new Currency((string) $row->currency_code, (string) $row->name_ar, (string) $row->name_en, (int) $row->decimals, (int) $row->active === 1 || $this->isBase((string) $row->currency_code));
    }

    /**
     * @throws AccountingException
     */
    private function lock(string $code): object
    {
        $this->ensureBase();

        $table = $this->db->prefixTable('accounting_currencies');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE currency_code = ? FOR UPDATE", [$code]);

        if ($row === null) {
            throw new AccountingException(AccountingError::CurrencyNotFound, "Currency {$code} does not exist.", ['code' => $code]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function code(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new AccountingException(AccountingError::CurrencyCodeInvalid, 'The code of a currency is three capital letters.');
        }

        return $code;
    }

    /**
     * @throws AccountingException
     */
    private function name(mixed $value): string
    {
        $name = trim((string) $value);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::CurrencyNameInvalid, 'The name of a currency is 1 to 100 characters in each language.', ['max' => self::NAME_MAX]);
        }

        return $name;
    }

    /**
     * @throws AccountingException
     */
    private function decimals(mixed $value): int
    {
        $text = trim((string) $value);

        if (! in_array($text, array_map('strval', CurrencyCatalog::DECIMALS), true)) {
            throw new AccountingException(AccountingError::CurrencyDecimalsInvalid, 'A currency is kept in zero, two or three decimals.');
        }

        return (int) $text;
    }
}
