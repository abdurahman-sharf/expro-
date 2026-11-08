<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankDirectory;
use App\Libraries\Accounting\Bank\BankMatches;
use App\Libraries\Accounting\Bank\BankMatchSettings;
use App\Libraries\Accounting\Bank\BankReconciliation;
use App\Libraries\Accounting\Bank\ImportResult;
use App\Libraries\Accounting\Bank\ReconciliationFigures;
use App\Libraries\Accounting\Bank\ReconciliationReport;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\Bank\StatementParser;
use App\Libraries\Accounting\Bank\StatementRejected;
use App\Libraries\Accounting\Bank\StatementRowError;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * The statements of the bank accounts and their reconciliation with the books: the bank accounts the
 * employee may reconcile, the import of a statement from a file, its lines, the suggestions and the
 * matches, the reconciliation statement and its closing. It needs the module of the treasuries and
 * the permission to reconcile, and a bank account of a branch the employee works in that they may
 * deposit into or withdraw from; any other bank account is a page that is not there.
 */
class AccountingBankReconciliation extends AccountingController
{
    use ExportsCsv;

    private const PAGE_SIZE = 50;

    private BankReconciliation $bank;
    private TreasuryService $treasuries;

    public function __construct()
    {
        parent::__construct('accounting_treasuries');

        $this->bank       = new BankReconciliation(null, null, $this->branchGuard());
        $this->treasuries = new TreasuryService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $this->requireReconcile();

        $accounts = [];

        foreach ($this->bank->bankAccounts($this->employeeId()) as $treasury) {
            $id         = (int) $treasury->treasury_id;
            $unmatched  = $this->bank->lines($id, $this->employeeId(), 'unmatched', limit: 1)['total'];
            $closings   = array_values(array_filter($this->bank->closings($id, $this->employeeId()), static fn (object $closing): bool => $closing->status === 'closed'));
            $accounts[] = [
                'treasury'      => $treasury,
                'bank'          => $this->bankName($treasury),
                'lacksDetails'  => TreasuryService::lacksBankDetails($treasury),
                'balance'       => $this->formatAmount($this->treasuries->position($id)->balance),
                'unmatched'     => $unmatched,
                'closedThrough' => $closings === [] ? '' : $this->formatDate((string) $closings[0]->closed_through),
            ];
        }

        return view('accounting_bank/index', ['accounts' => $accounts]);
    }

    public function getAccount(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);
        $closings = $this->bank->closings($treasuryId, $this->employeeId());
        $latest   = null;

        foreach ($closings as $closing) {
            if ($closing->status === 'closed') {
                $latest = (int) $closing->reconciliation_id;

                break;
            }
        }

        return view('accounting_bank/account', [
            'treasury'     => $treasury,
            'bank'         => $this->bankName($treasury),
            'lacksDetails' => TreasuryService::lacksBankDetails($treasury),
            'balance'      => $this->formatAmount($this->treasuries->position($treasuryId)->balance),
            'statements'   => $this->bank->statements($treasuryId, $this->employeeId()),
            'closings'     => $closings,
            'latest'       => $latest,
            'window'       => $this->bank->windowDays(),
            'windowMin'    => BankMatchSettings::MIN,
            'windowMax'    => BankMatchSettings::MAX,
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'when'         => fn (?string $value): string => $this->formatDateTime($value),
        ]);
    }

    public function getImport(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);

        return view('accounting_bank/import', [
            'treasury'   => $treasury,
            'maxMb'      => StatementParser::MAX_BYTES / 1048576,
            'maxRows'    => StatementParser::MAX_ROWS,
            'delimiters' => array_keys(StatementMapping::DELIMITERS),
            'dateOrders' => StatementMapping::DATE_ORDERS,
            'maxColumn'  => StatementMapping::MAX_COLUMN,
        ]);
    }

    public function getSample(): ResponseInterface
    {
        $this->requireReconcile();

        $rows = [['Date', 'Description', 'Deposit', 'Withdrawal', 'Reference'], ['2026-03-01', 'Transfer from customer', '1500.00', '', 'TRX-1001'], ['2026-03-02', 'Bank fee', '', '12.50', 'FEE-0302']];
        $text = implode("\r\n", array_map(static fn (array $row): string => implode(',', $row), $rows)) . "\r\n";

        return $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8')->setHeader('Content-Disposition', 'attachment; filename="bank-statement-sample.csv"')->setBody("\xEF\xBB\xBF" . $text);
    }

    public function postImport(int $treasuryId): ResponseInterface
    {
        $this->visible($treasuryId);

        try {
            [$name, $content] = $this->uploaded();

            $result = $this->bank->import($treasuryId, $name, $content, StatementMapping::fromInput((array) $this->request->getPost()), $this->employeeId());
        } catch (StatementRejected $rejected) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $this->messageFor($rejected),
                'errors'  => array_map(fn (StatementRowError $error): string => $this->rowError($error), $rejected->errors),
                'id'      => NEW_ENTRY,
            ]);
        } catch (AccountingException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageFor($exception), 'id' => NEW_ENTRY]);
        }

        return $this->response->setJSON([
            'success'    => true,
            'created'    => $result->created(),
            'status'     => $result->status,
            'message'    => $this->importMessage($result),
            'id'         => $result->statementId ?? NEW_ENTRY,
            'imported'   => $result->imported,
            'duplicates' => $result->duplicates,
        ]);
    }

    public function postDeleteStatement(int $statementId): ResponseInterface
    {
        return $this->attempt(function () use ($statementId): array {
            $this->requireReconcile();

            $this->bank->deleteStatement($statementId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_bank.statement_deleted'), 'id' => $statementId];
        });
    }

    public function getLines(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);
        $state    = in_array($this->request->getGet('state'), ['matched', 'unmatched'], true) ? (string) $this->request->getGet('state') : 'all';
        $from     = $this->dateParameter('from');
        $to       = $this->dateParameter('to');
        $page     = max(1, (int) $this->request->getGet('page'));
        $result   = $this->bank->lines($treasuryId, $this->employeeId(), $state, $from, $to, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return view('accounting_bank/lines', [
            'treasury' => $treasury,
            'state'    => $state,
            'from'     => $from ?? '',
            'to'       => $to ?? '',
            'page'     => $page,
            'pages'    => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'total'    => $result['total'],
            'rows'     => $result['rows'],
            'amount'   => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'     => fn (?string $value): string => $this->formatDate($value),
        ]);
    }

    public function getMatches(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);

        return view('accounting_bank/matches', [
            'treasury'     => $treasury,
            'matches'      => $this->bank->matches($treasuryId, $this->employeeId()),
            'journalLinks' => $this->can('accounting_journals'),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'number'       => fn (object $line): string => $this->displayNumber((string) $line->series, (string) $line->number),
        ]);
    }

    public function getMatch(int $treasuryId, int $lineId): string
    {
        $treasury = $this->visible($treasuryId);

        try {
            $result = $this->bank->linesToMatch($treasuryId, $lineId, $this->employeeId());
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_bank/match', [
            'treasury'     => $treasury,
            'line'         => $result['line'],
            'candidates'   => $result['lines'],
            'more'         => $result['more'],
            'window'       => $this->bank->windowDays(),
            'journalLinks' => $this->can('accounting_journals'),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'number'       => fn (object $candidate): string => $this->displayNumber((string) $candidate->series, (string) $candidate->number),
        ]);
    }

    public function postMatch(int $treasuryId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $this->requireReconcile();

            $ids     = $this->request->getPost('journal_line_ids');
            $matchId = $this->bank->match(
                $treasuryId,
                (int) $this->request->getPost('line_id'),
                is_array($ids) ? array_map('intval', $ids) : [],
                $this->employeeId(),
                $this->request->getPost('method') === BankMatches::SUGGESTED ? BankMatches::SUGGESTED : BankMatches::MANUAL,
            );

            return ['success' => true, 'message' => lang('Accounting_bank.matched'), 'id' => $matchId];
        });
    }

    public function postUnmatch(int $treasuryId, int $matchId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId, $matchId): array {
            $this->requireReconcile();

            $this->bank->unmatch($treasuryId, $matchId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_bank.unmatched'), 'id' => $matchId];
        });
    }

    public function getSuggestions(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);

        return view('accounting_bank/suggestions', [
            'treasury'     => $treasury,
            'suggestions'  => $this->bank->suggestions($treasuryId, $this->employeeId()),
            'window'       => $this->bank->windowDays(),
            'journalLinks' => $this->can('accounting_journals'),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'number'       => fn (object $candidate): string => $this->displayNumber((string) $candidate->series, (string) $candidate->number),
        ]);
    }

    public function getReport(int $treasuryId): string
    {
        $treasury = $this->visible($treasuryId);
        $asOf     = $this->dateParameter('as_of') ?? date('Y-m-d');
        $balance  = $this->queryText('statement_balance');
        $error    = '';
        $figures  = null;

        try {
            $figures = $this->bank->report($treasuryId, $asOf, $balance === '' ? null : $balance, $this->employeeId());
        } catch (AccountingException $exception) {
            if ($exception->error === AccountingError::BankAccountNotFound) {
                throw PageNotFoundException::forPageNotFound();
            }

            $error   = $this->messageFor($exception);
            $figures = $this->bank->report($treasuryId, $asOf, null, $this->employeeId());
        }

        return view('accounting_bank/report', [
            'treasury'     => $treasury,
            'figures'      => $figures,
            'asOf'         => $asOf,
            'balanceInput' => $balance,
            'error'        => $error,
            'journalLinks' => $this->can('accounting_journals'),
            'listLimit'    => ReconciliationReport::LIST_LIMIT,
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'number'       => fn (object $line): string => $this->displayNumber((string) $line->series, (string) $line->number),
            'csvUrl'       => base_url('accounting_treasuries/bank/' . $treasuryId . '/report/csv') . '?' . http_build_query(array_filter(['as_of' => $asOf, 'statement_balance' => $balance], static fn (string $value): bool => $value !== '')),
        ]);
    }

    public function getReportCsv(int $treasuryId): ResponseInterface
    {
        $treasury = $this->visible($treasuryId);
        $asOf     = $this->dateParameter('as_of') ?? date('Y-m-d');
        $balance  = $this->queryText('statement_balance');

        try {
            $figures = $this->bank->report($treasuryId, $asOf, $balance === '' ? null : $balance, $this->employeeId());
        } catch (AccountingException $exception) {
            if ($exception->error === AccountingError::BankAccountNotFound) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($exception));
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="bank_reconciliation_' . $treasuryId . '_' . $asOf . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->reportCsv($treasury, $figures));
    }

    public function postClose(int $treasuryId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $this->requireReconcile();

            $id = $this->bank->close($treasuryId, trim((string) $this->request->getPost('through')), trim((string) $this->request->getPost('statement_balance')), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_bank.closed'), 'id' => $id];
        });
    }

    public function postReopen(int $reconciliationId): ResponseInterface
    {
        return $this->attempt(function () use ($reconciliationId): array {
            $this->requireReconcile();

            $this->bank->reopen($reconciliationId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_bank.reopened'), 'id' => $reconciliationId];
        });
    }

    public function postWindow(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $this->requireReconcile();

            $this->bank->setWindowDays($this->request->getPost('days'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_bank.window_saved'), 'id' => NEW_ENTRY];
        });
    }

    private function requireReconcile(): void
    {
        if (! $this->can(AccountingGuard::BANK_RECONCILE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function visible(int $treasuryId): object
    {
        $this->requireReconcile();

        try {
            return $this->bank->bankAccount($treasuryId, $this->employeeId());
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * @return array{string, string} The name and the text of the statement: the file when one was
     *                               chosen, else the text that was pasted.
     *
     * @throws AccountingException
     */
    private function uploaded(): array
    {
        $file = $this->request->getFile('statement_file');

        if ($file !== null && $file->getError() === UPLOAD_ERR_OK && $file->getSize() > 0) {
            if ($file->getSize() > StatementParser::MAX_BYTES) {
                throw new AccountingException(AccountingError::BankStatementTooLarge, 'The file is larger than the limit.', ['max' => StatementParser::MAX_BYTES / 1048576]);
            }

            return [$file->getClientName(), (string) file_get_contents($file->getTempName())];
        }

        return ['pasted.csv', (string) $this->request->getPost('statement_text')];
    }

    private function importMessage(ImportResult $result): string
    {
        return match ($result->status) {
            ImportResult::IMPORTED       => lang('Accounting_bank.import_done', [$result->imported, $result->duplicates]),
            ImportResult::DUPLICATE_FILE => lang('Accounting_bank.import_same_file'),
            default                      => lang('Accounting_bank.import_same_lines', [$result->rowCount]),
        };
    }

    private function rowError(StatementRowError $error): string
    {
        return lang('Accounting_bank.row_error', [$error->rowNumber, lang('Accounting_bank.row_error_' . $error->reason), $error->value]);
    }

    private function dateParameter(string $name): ?string
    {
        $value = $this->queryText($name);
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function queryText(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
    }

    private function bankName(object $treasury): string
    {
        $code = (string) ($treasury->bank_code ?? '');
        $name = $code === '' ? null : BankDirectory::nameOf($code, service('language')->getLocale());

        return $name ?? (string) ($treasury->bank_name ?? '');
    }

    private function reportCsv(object $treasury, ReconciliationFigures $figures): string
    {
        $handle  = fopen('php://temp', 'r+b');
        $pair    = fn (string $label, int $count, string $total): array => [$label . ' (' . $count . ')', $total];
        $journal = fn (object $line): array => [(string) $line->journal_date, $this->displayNumber((string) $line->series, (string) $line->number), (string) $line->description, (string) $line->amount];

        $this->csvRow($handle, [lang('Accounting_bank.report_title', [(string) $treasury->name])]);
        $this->csvRow($handle, [lang('Accounting_bank.as_of'), $figures->asOf]);

        if ($figures->closedThrough !== null) {
            $this->csvRow($handle, [lang('Accounting_bank.closed_up_to', [$figures->closedThrough])]);
        }

        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_bank.side_books')]);
        $this->csvRow($handle, [lang('Accounting_bank.book_balance'), $figures->bookBalance]);
        $this->csvRow($handle, $pair(lang('Accounting_bank.unmatched_statement'), $figures->unmatchedStatement['count'], $figures->unmatchedStatement['total']));
        $this->csvRow($handle, [lang('Accounting_bank.adjusted_book'), $figures->adjustedBook]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_bank.side_bank')]);
        $this->csvRow($handle, [lang('Accounting_bank.statement_balance'), $figures->statementBalance ?? lang('Accounting_bank.not_entered')]);
        $this->csvRow($handle, $pair(lang('Accounting_bank.outstanding_books'), $figures->outstandingBook['count'], $figures->outstandingBook['total']));
        $this->csvRow($handle, [lang('Accounting_bank.adjusted_bank'), $figures->adjustedBank ?? '']);
        $this->csvRow($handle, [lang('Accounting_bank.difference'), $figures->difference === null ? lang('Accounting_bank.not_computed') : ($figures->reconciled() ? lang('Accounting_bank.reconciled') : $figures->difference)]);
        $this->csvRow($handle, [lang('Accounting_bank.statement_lines_total'), $figures->statementLinesTotal]);
        $this->csvRow($handle, $pair(lang('Accounting_bank.carried'), $figures->carriedBook['count'], $figures->carriedBook['total']));
        $this->csvRow($handle, [lang('Accounting_bank.matches_made'), (string) $figures->matchesMade]);

        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_bank.unmatched_statement')]);
        $this->csvRow($handle, [lang('Accounting_bank.date'), lang('Accounting_bank.description'), lang('Accounting_bank.reference'), lang('Accounting_bank.amount')]);

        foreach ($figures->statementLines as $line) {
            $this->csvRow($handle, [(string) $line->line_date, (string) $line->description, (string) $line->reference, (string) $line->amount]);
        }

        foreach ([['outstanding_books', $figures->bookLines], ['carried', $figures->carriedLines]] as [$heading, $lines]) {
            if ($lines === []) {
                continue;
            }

            $this->csvRow($handle, []);
            $this->csvRow($handle, [lang('Accounting_bank.' . $heading)]);
            $this->csvRow($handle, [lang('Accounting_bank.date'), lang('Accounting_bank.journal'), lang('Accounting_bank.description'), lang('Accounting_bank.amount')]);

            foreach ($lines as $line) {
                $this->csvRow($handle, $journal($line));
            }
        }

        if (max($figures->unmatchedStatement['count'], $figures->outstandingBook['count'], $figures->carriedBook['count']) > ReconciliationReport::LIST_LIMIT) {
            $this->csvNote($handle, lang('Accounting_bank.list_limit', [ReconciliationReport::LIST_LIMIT]));
        }

        return $this->csvText($handle);
    }
}
