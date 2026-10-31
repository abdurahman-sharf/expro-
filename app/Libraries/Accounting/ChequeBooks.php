<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The cheque books of the bank accounts, each with the range of serial numbers its cheques may
 * have. A cheque paid to a supplier is written out of an active book. A book is in the branch of its
 * bank account, and one that has written cheques is never deleted: it is put out of use.
 */
final class ChequeBooks
{
    use RunsInTransaction;

    public const ENTITY      = 'cheque_book';
    private const NAME_MAX   = 100;
    private const SERIAL_MAX = 18;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private TreasuryService $treasuries;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->treasuries = new TreasuryService($this->db, null, $this->branches);
        $this->audit      = new AuditLog($this->db);
    }

    /**
     * @param array{treasury_id?: int|string, name?: string, first_serial?: string, last_serial?: string} $input
     *
     * @return int The book.
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $treasury       = $this->bank((int) ($input['treasury_id'] ?? 0));
        $name           = $this->name((string) ($input['name'] ?? ''));
        [$first, $last] = $this->range($input);

        $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);

        return $this->transactional(function () use ($treasury, $name, $first, $last, $employeeId): int {
            $this->db->table('cheque_books')->insert([
                'company_id'   => CompanyContext::id(),
                'branch_id'    => (int) $treasury->branch_id,
                'treasury_id'  => (int) $treasury->treasury_id,
                'name'         => $name,
                'first_serial' => $first,
                'last_serial'  => $last,
                'created_by'   => $employeeId,
            ]);

            $bookId = (int) $this->db->insertID();

            $this->audit->write(self::ENTITY, $bookId, 'created', $employeeId, JournalOrigin::User, ['first' => $first, 'last' => $last], (int) $treasury->branch_id);

            return $bookId;
        });
    }

    /**
     * Renames a book, puts it in or out of use, or moves the ends of its range, which cannot leave a
     * cheque that was written out of it outside.
     *
     * @param array{name?: string, active?: bool|int|string, first_serial?: string, last_serial?: string} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $bookId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $book = $this->find($bookId);

        $this->branches->requireBranch((int) $book->branch_id, $employeeId);

        $name           = array_key_exists('name', $input) ? $this->name((string) $input['name']) : (string) $book->name;
        [$first, $last] = $this->range([
            'first_serial' => $input['first_serial'] ?? $book->first_serial,
            'last_serial'  => $input['last_serial'] ?? $book->last_serial,
        ]);
        $active = array_key_exists('active', $input) ? (int) (bool) $input['active'] : (int) $book->active;

        $outside = $this->db->table('cheques')->where('book_id', $bookId)->where('deleted', 0)->groupStart()->where('CAST(cheque_number AS UNSIGNED) <', $first)->orWhere('CAST(cheque_number AS UNSIGNED) >', $last)->groupEnd()->countAllResults();

        if ($outside > 0) {
            throw new AccountingException(AccountingError::ChequeBookInvalid, 'The range of the book would leave a cheque written out of it outside.', ['book_id' => $bookId]);
        }

        $this->transactional(function () use ($bookId, $name, $first, $last, $active, $employeeId, $book): void {
            $this->db->table('cheque_books')->where('book_id', $bookId)->update(['name' => $name, 'first_serial' => $first, 'last_serial' => $last, 'active' => $active]);
            $this->audit->write(self::ENTITY, $bookId, 'updated', $employeeId, JournalOrigin::User, ['active' => $active, 'first' => $first, 'last' => $last], (int) $book->branch_id);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function delete(int $bookId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $book = $this->find($bookId);

        $this->branches->requireBranch((int) $book->branch_id, $employeeId);

        $this->transactional(function () use ($bookId, $employeeId, $book): void {
            if ($this->db->table('cheques')->where('book_id', $bookId)->countAllResults() > 0) {
                throw new AccountingException(AccountingError::ChequeBookInUse, 'A book that cheques were written out of is put out of use, not deleted.', ['book_id' => $bookId]);
            }

            $this->db->table('cheque_books')->where('book_id', $bookId)->delete();
            $this->audit->write(self::ENTITY, $bookId, 'deleted', $employeeId, JournalOrigin::User, [], (int) $book->branch_id);
        });
    }

    /**
     * The books, newest first, with their bank and the cheques written out of each.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     *
     * @return list<object>
     */
    public function all(?array $branchIds = null, bool $onlyActive = false): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->db->table('cheque_books k')
            ->select('k.*, t.name AS treasury_name, b.code AS branch_code, (SELECT COUNT(*) FROM ' . $this->db->prefixTable('cheques') . ' c WHERE c.book_id = k.book_id AND c.deleted = 0) AS written', false)
            ->join('treasuries t', 't.treasury_id = k.treasury_id')
            ->join('branches b', 'b.branch_id = k.branch_id')
            ->where('k.company_id', CompanyContext::id())
            ->orderBy('k.book_id', 'DESC');

        if ($branchIds !== null) {
            $builder->whereIn('k.branch_id', $branchIds);
        }

        if ($onlyActive) {
            $builder->where('k.active', 1);
        }

        return $builder->get()->getResult();
    }

    /**
     * @throws AccountingException
     */
    public function find(int $bookId): object
    {
        $book = $this->db->table('cheque_books')->where(['company_id' => CompanyContext::id(), 'book_id' => $bookId])->get()->getRow();

        if ($book === null) {
            throw new AccountingException(AccountingError::ChequeBookNotFound, "Cheque book {$bookId} does not exist.", ['book_id' => $bookId]);
        }

        return $book;
    }

    /**
     * @throws AccountingException
     */
    private function bank(int $treasuryId): object
    {
        $treasury = $this->treasuries->find($treasuryId);

        if ($treasury === null || (int) $treasury->active === 0 || $treasury->kind !== TreasuryService::BANK) {
            throw new AccountingException(AccountingError::ChequeTreasuryInvalid, 'A cheque book belongs to an active bank account.', ['treasury_id' => $treasuryId]);
        }

        return $treasury;
    }

    /**
     * @throws AccountingException
     */
    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::InvalidName, 'The name of a cheque book is 1 to 100 characters.');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{string, string}
     *
     * @throws AccountingException
     */
    private function range(array $input): array
    {
        $first = trim((string) ($input['first_serial'] ?? ''));
        $last  = trim((string) ($input['last_serial'] ?? ''));
        $valid = '/^\d{1,' . self::SERIAL_MAX . '}$/';

        if (preg_match($valid, $first) !== 1 || preg_match($valid, $last) !== 1 || bccomp($first, $last, 0) > 0) {
            throw new AccountingException(AccountingError::ChequeBookInvalid, 'A book has a first and a last serial number, the first not after the last.');
        }

        return [$first, $last];
    }
}
