<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The rate of a currency to the base currency by day: how much of the base currency one unit is worth.
 * A document takes the rate of the latest day on or before its own date. Changing a rate changes money,
 * so adding, changing and removing one asks the permission to manage currencies and is written to the
 * audit log with what it was before. A rate of the table never changes a document or a journal that
 * has been posted: they keep the rate they were captured at.
 */
final class CurrencyRates
{
    use RunsInTransaction;

    private const RATE_PATTERN = '/^\d{1,10}(\.\d{1,8})?$/';
    private const NOTE_MAX     = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * The rate of a currency on a day, which is one for the base currency, and null when the currency
     * has no rate on or before the day.
     *
     * @return numeric-string|null
     */
    public function rateOn(string $code, string $date): ?string
    {
        if ($code === (new AccountingSettings($this->db))->baseCurrency()) {
            return '1.00000000';
        }

        $row = $this->db->table('accounting_currency_rates')
            ->select('rate')
            ->where('currency_code', $code)
            ->where('effective_date <=', $date)
            ->orderBy('effective_date', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        return $row === null ? null : bcadd((string) $row->rate, '0', 8);
    }

    /**
     * @return list<object> The newest day first.
     */
    public function history(?string $code = null): array
    {
        $builder = $this->db->table('accounting_currency_rates r')
            ->select("r.rate_id, r.currency_code, r.rate, r.effective_date, r.note, r.entered_by, r.created_at, TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS entered_name", false)
            ->join('people p', 'p.person_id = r.entered_by', 'left')
            ->orderBy('r.effective_date', 'DESC')
            ->orderBy('r.rate_id', 'DESC');

        if ($code !== null && $code !== '') {
            $builder->where('r.currency_code', $code);
        }

        return $builder->get()->getResult();
    }

    public function find(int $rateId): ?object
    {
        return $this->db->table('accounting_currency_rates')->where('rate_id', $rateId)->get()->getRow();
    }

    /**
     * @param array{currency_code?: mixed, rate?: mixed, effective_date?: mixed, note?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function add(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $code = $this->foreignCode($input['currency_code'] ?? '');
        $rate = $this->normalize((string) ($input['rate'] ?? ''));
        $date = $this->date((string) ($input['effective_date'] ?? ''));
        $note = $this->note($input['note'] ?? '');

        return $this->transactional(function () use ($code, $rate, $date, $note, $employeeId): int {
            $this->assertDayFree($code, $date, null);

            $this->db->table('accounting_currency_rates')->insert(['currency_code' => $code, 'rate' => $rate, 'effective_date' => $date, 'note' => $note, 'entered_by' => $employeeId]);

            $rateId = (int) $this->db->insertID();

            $this->audit->write('currency_rate', $rateId, 'created', $employeeId, JournalOrigin::User, ['code' => $code, 'rate' => $rate, 'date' => $date]);

            return $rateId;
        });
    }

    /**
     * @param array{rate?: mixed, effective_date?: mixed, note?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $rateId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $this->transactional(function () use ($rateId, $input, $employeeId): void {
            $row     = $this->lock($rateId);
            $changes = [];

            if (array_key_exists('rate', $input)) {
                $rate = $this->normalize((string) $input['rate']);

                if (bccomp($rate, (string) $row->rate, 8) !== 0) {
                    $changes['rate'] = $rate;
                }
            }

            if (array_key_exists('effective_date', $input)) {
                $date = $this->date((string) $input['effective_date']);

                if ($date !== $row->effective_date) {
                    $this->assertDayFree((string) $row->currency_code, $date, $rateId);
                    $changes['effective_date'] = $date;
                }
            }

            if (array_key_exists('note', $input)) {
                $note = $this->note($input['note']);

                if ($note !== (string) $row->note) {
                    $changes['note'] = $note;
                }
            }

            if ($changes === []) {
                return;
            }

            $this->db->table('accounting_currency_rates')->where('rate_id', $rateId)->update($changes + ['entered_by' => $employeeId]);
            $this->audit->write('currency_rate', $rateId, 'updated', $employeeId, JournalOrigin::User, ['code' => $row->currency_code, 'before' => array_intersect_key((array) $row, $changes), 'after' => $changes]);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function delete(int $rateId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CURRENCIES_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $this->transactional(function () use ($rateId, $employeeId): void {
            $row = $this->lock($rateId);

            $this->db->table('accounting_currency_rates')->where('rate_id', $rateId)->delete();
            $this->audit->write('currency_rate', $rateId, 'deleted', $employeeId, JournalOrigin::User, ['code' => $row->currency_code, 'rate' => $row->rate, 'date' => $row->effective_date]);
        });
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    public function normalize(string $rate): string
    {
        $rate = trim($rate);

        if (preg_match(self::RATE_PATTERN, $rate) !== 1 || bccomp($rate, '0', 8) <= 0) {
            throw new AccountingException(AccountingError::RateInvalid, 'A rate is a number above zero with at most eight decimals.');
        }

        return bcadd($rate, '0', 8);
    }

    /**
     * @throws AccountingException
     */
    private function lock(int $rateId): object
    {
        $table = $this->db->prefixTable('accounting_currency_rates');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE rate_id = ? FOR UPDATE", [$rateId]);

        if ($row === null) {
            throw new AccountingException(AccountingError::RateNotFound, "Rate {$rateId} does not exist.", ['rate_id' => $rateId]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function foreignCode(mixed $value): string
    {
        $code     = strtoupper(trim((string) $value));
        $currency = preg_match('/^[A-Z]{3}$/', $code) === 1 ? (new Currencies($this->db, $this->guard, $this->branches))->find($code) : null;

        if ($currency === null) {
            throw new AccountingException(AccountingError::CurrencyNotFound, "Currency {$code} does not exist.", ['code' => $code]);
        }

        if ($code === (new AccountingSettings($this->db))->baseCurrency()) {
            throw new AccountingException(AccountingError::CurrencyIsBase, 'The base currency is worth one and has no rate.', ['code' => $code]);
        }

        return $code;
    }

    /**
     * @throws AccountingException
     */
    private function date(string $value): string
    {
        $value  = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        return $value;
    }

    private function note(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, self::NOTE_MAX);
    }

    /**
     * @throws AccountingException
     */
    private function assertDayFree(string $code, string $date, ?int $exceptRateId): void
    {
        $builder = $this->db->table('accounting_currency_rates')->where('currency_code', $code)->where('effective_date', $date);

        if ($exceptRateId !== null) {
            $builder->where('rate_id !=', $exceptRateId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::RateDateTaken, "Currency {$code} has a rate for {$date} already.", ['code' => $code, 'date' => $date]);
        }
    }
}
