<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\ChequeDirection;
use App\Libraries\Accounting\ChequeList;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\ChequeStatus;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The cheques received from customers and paid to suppliers, the cheque books and the two cheque
 * reports. A person limited to some branches sees and changes only the cheques of those branches.
 * Every change of a cheque is a POST: a link never changes the status of a cheque.
 */
class AccountingCheques extends AccountingController
{
    use ExportsCsv;

    private const MAX_PAGE_SIZE = 500;
    private const ACTIONS       = ['deposit', 'collect', 'reject', 'deliver'];
    private const PARTY_LIMIT   = 20;

    private ChequeService $cheques;
    private ChequeBooks $books;
    private ChequeList $list;

    public function __construct()
    {
        parent::__construct('accounting_cheques');

        $this->cheques = new ChequeService(null, null, $this->branchGuard());
        $this->books   = new ChequeBooks(null, null, $this->branchGuard());
        $this->list    = new ChequeList();
    }

    public function getIndex(): string
    {
        $direction = $this->direction((string) $this->request->getGet('direction'));

        return view('accounting_cheques/manage', [
            'direction'    => $direction->value,
            'tableHeaders' => transform_headers($this->headers($direction), true, true),
            'statuses'     => $this->statusOptions($direction),
            'canManage'    => $this->can(AccountingGuard::CHEQUES_MANAGE),
            'scopeNotice'  => $this->scopeNoticeNeeded(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $direction = $this->direction((string) $this->request->getGet('direction'));
        $limit     = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result    = $this->list->search(
            $direction,
            $this->branchIds(),
            $this->filters(),
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            max(0, (int) $this->request->getGet('offset')),
        );

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $cheque): array => $this->row($cheque), $result['rows']),
        ]);
    }

    public function getRow(int $chequeId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->visible($chequeId)));
    }

    public function getDetail(int $chequeId): string
    {
        $cheque    = $this->visible($chequeId);
        $direction = ChequeDirection::from($cheque->direction);

        return view('accounting_cheques/view', [
            'cheque'       => $cheque,
            'direction'    => $direction->value,
            'number'       => $this->numberOf($cheque),
            'events'       => $this->cheques->events($chequeId),
            'journals'     => $this->cheques->journalsOf($chequeId),
            'journalLinks' => $this->can('accounting_journals'),
            'canManage'    => $this->can(AccountingGuard::CHEQUES_MANAGE),
            'actions'      => $this->cheques->actionsOf($cheque),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (?string $value): string => $this->formatDate($value),
            'sourceLabel'  => fn (string $source): string => $this->label('source', $source),
        ]);
    }

    public function getForm(string $direction, int $chequeId = NEW_ENTRY): string
    {
        $this->requireManage();

        $kind   = $this->direction($direction);
        $cheque = $chequeId === NEW_ENTRY ? null : $this->visible($chequeId);

        if ($cheque !== null && $cheque->direction !== $kind->value) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_cheques/form', [
            'cheque'     => $cheque,
            'direction'  => $kind->value,
            'treasuries' => $kind === ChequeDirection::Received ? $this->banksFor($cheque) : [],
            'books'      => $kind === ChequeDirection::Paid ? $this->booksFor($cheque) : [],
            'today'      => date('Y-m-d'),
            'locked'     => $cheque !== null && ! ChequeStatus::from($cheque->status)->isFirst(),
        ]);
    }

    public function getParties(string $direction): ResponseInterface
    {
        $this->requireManage();

        $parties = $this->list->parties($this->direction($direction), self::PARTY_LIMIT, trim((string) $this->request->getGet('term')));

        return $this->response->setJSON(array_map(static fn (object $party): array => ['value' => (int) $party->person_id, 'label' => $party->name], $parties));
    }

    public function postSaveCheque(string $direction, int $chequeId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($direction, $chequeId): array {
            $kind  = $this->direction($direction);
            $input = [
                'party_id'      => (int) $this->request->getPost('party_id'),
                'treasury_id'   => (int) $this->request->getPost('treasury_id'),
                'book_id'       => (int) $this->request->getPost('book_id'),
                'cheque_number' => (string) $this->request->getPost('cheque_number'),
                'amount'        => trim((string) $this->request->getPost('amount')),
                'issue_date'    => trim((string) $this->request->getPost('issue_date')),
                'due_date'      => trim((string) $this->request->getPost('due_date')),
                'drawer_name'   => (string) $this->request->getPost('drawer_name'),
                'endorsed_to'   => (string) $this->request->getPost('endorsed_to'),
                'description'   => (string) $this->request->getPost('description'),
            ];

            if ($chequeId !== NEW_ENTRY) {
                $this->visible($chequeId);
                $this->cheques->update($chequeId, $input, $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_cheques.successful_updating'), 'id' => $chequeId];
            }

            $deliverOn = $kind === ChequeDirection::Paid && $this->request->getPost('deliver') === '1' ? $input['issue_date'] : null;
            $id        = $kind === ChequeDirection::Received
                ? $this->cheques->receive($input, $this->employeeId())
                : $this->cheques->issue($input, $this->employeeId(), $deliverOn);

            return ['success' => true, 'message' => lang('Accounting_cheques.successful_adding'), 'id' => $id];
        });
    }

    public function getActionForm(string $action, int $chequeId): string
    {
        $this->requireManage();

        $cheque = $this->visible($chequeId);

        if (! in_array($action, self::ACTIONS, true) || ! in_array($action, $this->cheques->actionsOf($cheque), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_cheques/action_form', [
            'cheque'   => $cheque,
            'action'   => $action,
            'today'    => date('Y-m-d'),
            'asksFees' => $action === 'collect' && $cheque->status === ChequeStatus::Deposited->value,
            'number'   => $this->numberOf($cheque),
        ]);
    }

    public function postAct(string $action, int $chequeId): ResponseInterface
    {
        return $this->attempt(function () use ($action, $chequeId): array {
            $this->visible($chequeId);

            $date     = trim((string) $this->request->getPost('date'));
            $note     = (string) $this->request->getPost('note');
            $feesText = $this->request->getPost('fees');
            $fees     = $feesText === null ? null : trim((string) $feesText);

            match ($action) {
                'deposit' => $this->cheques->deposit($chequeId, $date, $this->employeeId(), $note),
                'collect' => $this->cheques->collect($chequeId, $date, $fees, $this->employeeId(), $note),
                'reject'  => $this->cheques->reject($chequeId, $date, $this->employeeId(), $note),
                'deliver' => $this->cheques->deliver($chequeId, $date, $this->employeeId(), $note),
                default   => throw new AccountingException(AccountingError::ChequeStatusInvalid, "There is no action {$action}."),
            };

            return ['success' => true, 'message' => lang('Accounting_cheques.done_' . $action), 'id' => $chequeId];
        });
    }

    public function postCancel(int $chequeId): ResponseInterface
    {
        return $this->attempt(function () use ($chequeId): array {
            $this->visible($chequeId);
            $this->cheques->cancel($chequeId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.done_cancel'), 'id' => $chequeId];
        });
    }

    public function postUndo(int $chequeId): ResponseInterface
    {
        return $this->attempt(function () use ($chequeId): array {
            $this->visible($chequeId);
            $this->cheques->undo($chequeId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.done_undo'), 'id' => $chequeId];
        });
    }

    public function postDeleteCheque(int $chequeId): ResponseInterface
    {
        return $this->attempt(function () use ($chequeId): array {
            $this->visible($chequeId);
            $this->cheques->delete($chequeId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.done_delete'), 'id' => $chequeId];
        });
    }

    public function getBooks(): string
    {
        return view('accounting_cheques/books', [
            'books'     => $this->books->all($this->branchIds()),
            'canManage' => $this->can(AccountingGuard::CHEQUES_MANAGE),
        ]);
    }

    public function getBookForm(int $bookId = NEW_ENTRY): string
    {
        $this->requireManage();

        return view('accounting_cheques/book_form', [
            'book'  => $bookId === NEW_ENTRY ? null : $this->visibleBook($bookId),
            'banks' => array_values(array_filter((new TreasuryService(null, null, $this->branchGuard()))->all($this->branchIds(), false), static fn (object $treasury): bool => $treasury->kind === TreasuryService::BANK)),
        ]);
    }

    public function postSaveBook(int $bookId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($bookId): array {
            $input = [
                'treasury_id'  => (int) $this->request->getPost('treasury_id'),
                'name'         => (string) $this->request->getPost('name'),
                'first_serial' => trim((string) $this->request->getPost('first_serial')),
                'last_serial'  => trim((string) $this->request->getPost('last_serial')),
            ];

            if ($bookId === NEW_ENTRY) {
                return ['success' => true, 'message' => lang('Accounting_cheques.book_added'), 'id' => $this->books->create($input, $this->employeeId())];
            }

            $this->visibleBook($bookId);
            $this->books->update($bookId, ['name' => $input['name'], 'first_serial' => $input['first_serial'], 'last_serial' => $input['last_serial']], $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.book_updated'), 'id' => $bookId];
        });
    }

    public function postBookActive(int $bookId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($bookId, $active): array {
            $this->visibleBook($bookId);
            $this->books->update($bookId, ['active' => $active === 1], $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.book_updated'), 'id' => $bookId];
        });
    }

    public function postDeleteBook(int $bookId): ResponseInterface
    {
        return $this->attempt(function () use ($bookId): array {
            $this->visibleBook($bookId);
            $this->books->delete($bookId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_cheques.book_deleted'), 'id' => $bookId];
        });
    }

    public function getReport(string $direction): string
    {
        $kind    = $this->direction($direction);
        $filters = $this->filters();
        $report  = $this->list->report($kind, $this->branchIds(), $filters);

        return view('accounting_cheques/report', [
            'direction' => $kind->value,
            'filters'   => $filters,
            'statuses'  => $this->statusOptions($kind),
            'report'    => $report,
            'number'    => fn (object $cheque): string => (string) ($cheque->document_number ?? $this->fallbackNumber($cheque)),
            'amount'    => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'      => fn (?string $value): string => $this->formatDate($value),
            'status'    => static fn (string $value): string => lang('Accounting_cheques.status_' . $value),
            'csvUrl'    => base_url('accounting_cheques/report/' . $kind->value . '/csv') . $this->reportQuery($filters),
        ]);
    }

    public function getReportCsv(string $direction): ResponseInterface
    {
        $kind   = $this->direction($direction);
        $report = $this->list->report($kind, $this->branchIds(), $this->filters());

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="cheques_' . $kind->value . '_' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->reportCsv($kind, $report));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function reportQuery(array $filters): string
    {
        $query = [];

        foreach (['status', 'date_from', 'date_to', 'due_from', 'due_to'] as $name) {
            if (($filters[$name] ?? '') !== '') {
                $query[$name] = $filters[$name];
            }
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * @param array{rows: list<object>, truncated: bool, totals: array<string, string>, total: string, ties: list<array{key: string, account: ?object, ledger: string, cheques: string, agrees: bool}>} $report
     */
    private function reportCsv(ChequeDirection $kind, array $report): string
    {
        $handle = fopen('php://temp', 'r+b');
        $status = static fn (string $value): string => lang('Accounting_cheques.status_' . $value);
        $blank  = array_fill(0, 7, '');

        $this->csvRow($handle, [lang('Accounting_cheques.report_' . $kind->value)]);
        $this->csvRow($handle, [
            lang('Accounting_cheques.number'),
            lang('Accounting_cheques.cheque_number'),
            lang('Accounting_cheques.drawer_name'),
            lang($kind === ChequeDirection::Received ? 'Accounting_cheques.customer' : 'Accounting_cheques.supplier'),
            lang('Accounting_cheques.bank'),
            lang('Accounting_cheques.issue_date'),
            lang('Accounting_cheques.due_date'),
            lang('Accounting_cheques.status'),
            lang('Accounting_cheques.amount'),
        ]);

        foreach ($report['rows'] as $cheque) {
            $this->csvRow($handle, [
                (string) ($cheque->document_number ?? $this->fallbackNumber($cheque)),
                (string) $cheque->cheque_number,
                (string) $cheque->drawer_name,
                (string) $cheque->party_name,
                (string) $cheque->treasury_name,
                (string) $cheque->issue_date,
                (string) $cheque->due_date,
                $status((string) $cheque->status),
                (string) $cheque->amount,
            ]);
        }

        foreach ($report['totals'] as $key => $total) {
            $this->csvRow($handle, [...$blank, $status($key), $total]);
        }

        $this->csvRow($handle, [...$blank, lang('Accounting_cheques.total'), $report['total']]);

        if ($report['truncated']) {
            $this->csvNote($handle, lang('Accounting_cheques.report_truncated', [ChequeList::REPORT_LIMIT]));
        }

        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_cheques.tie_account'), lang('Accounting_cheques.tie_ledger'), lang('Accounting_cheques.tie_cheques'), lang('Accounting_cheques.tie_result')]);

        foreach ($report['ties'] as $tie) {
            $this->csvRow($handle, [
                $tie['account'] === null ? lang('Accounting_cheques.tie_no_account') : $tie['account']->code . ' - ' . $tie['account']->name,
                $tie['ledger'],
                $tie['cheques'],
                lang($tie['agrees'] ? 'Accounting_cheques.tie_agrees' : 'Accounting_cheques.tie_differs'),
            ]);
        }

        return $this->csvText($handle);
    }

    private function requireManage(): void
    {
        if (! $this->can(AccountingGuard::CHEQUES_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function direction(string $value): ChequeDirection
    {
        return ChequeDirection::tryFrom($value) ?? ChequeDirection::Received;
    }

    /**
     * A cheque of a branch the person may see, or a page that is not there.
     */
    private function visible(int $chequeId): object
    {
        $cheque = $this->cheques->find($chequeId);

        if ($cheque === null || (int) $cheque->deleted === 1 || ! $this->branchGuard()->canAccess((int) $cheque->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $cheque;
    }

    private function visibleBook(int $bookId): object
    {
        try {
            $book = $this->books->find($bookId);
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $this->branchGuard()->canAccess((int) $book->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $book;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'search'    => trim((string) $this->request->getGet('search')),
            'status'    => (string) $this->request->getGet('status'),
            'party_id'  => (int) $this->request->getGet('party_id'),
            'date_from' => (string) $this->request->getGet('date_from'),
            'date_to'   => (string) $this->request->getGet('date_to'),
            'due_from'  => (string) $this->request->getGet('due_from'),
            'due_to'    => (string) $this->request->getGet('due_to'),
            'deleted'   => $this->request->getGet('deleted') === '1',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(ChequeDirection $direction): array
    {
        $options = [];

        foreach (ChequeStatus::of($direction) as $status) {
            $options[$status->value] = lang($status->labelKey());
        }

        return $options;
    }

    /**
     * The active bank accounts of the branches the person may see and, for a cheque already entered, the
     * treasury it has even when that one is out of use or is no bank since, so that saving the cheque
     * again keeps it.
     *
     * @return list<object>
     */
    private function banksFor(?object $cheque): array
    {
        return array_values(array_filter(
            (new TreasuryService(null, null, $this->branchGuard()))->all($this->branchIds()),
            static fn (object $treasury): bool => ((int) $treasury->active === 1 && $treasury->kind === TreasuryService::BANK) || ($cheque !== null && (int) $treasury->treasury_id === (int) $cheque->treasury_id),
        ));
    }

    /**
     * The books in use and, for a cheque already written, its own book even when it is out of use since.
     *
     * @return list<object>
     */
    private function booksFor(?object $cheque): array
    {
        return array_values(array_filter(
            $this->books->all($this->branchIds()),
            static fn (object $book): bool => (int) $book->active === 1 || ($cheque !== null && (int) $book->book_id === (int) $cheque->book_id),
        ));
    }

    private function numberOf(object $cheque): string
    {
        $type = $cheque->direction === ChequeDirection::Received->value ? DocumentType::ReceivedCheque : DocumentType::PaidCheque;

        return (new DocumentNumbering())->label($type, 'cheques', (int) $cheque->cheque_id);
    }

    private function fallbackNumber(object $cheque): string
    {
        return ($cheque->direction === ChequeDirection::Received->value ? DocumentType::ReceivedCheque : DocumentType::PaidCheque)->fallback((int) $cheque->cheque_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(ChequeDirection $direction): array
    {
        return [
            ['cheque_number' => lang('Accounting_cheques.number'), 'sortable' => true, 'escape' => false],
            ['party_name'    => lang($direction === ChequeDirection::Received ? 'Accounting_cheques.customer' : 'Accounting_cheques.supplier'), 'sortable' => true],
            ['treasury_name' => lang('Accounting_cheques.bank'), 'sortable' => false],
            ['issue_date'    => lang('Accounting_cheques.issue_date'), 'sortable' => true],
            ['due_date'      => lang('Accounting_cheques.due_date'), 'sortable' => true],
            ['amount'        => lang('Accounting_cheques.amount'), 'sortable' => true, 'escape' => false],
            ['status'        => lang('Accounting_cheques.status'), 'sortable' => true, 'escape' => false],
            ['branch_code'   => lang('Accounting_cheques.branch'), 'sortable' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $cheque): array
    {
        $id      = (int) $cheque->cheque_id;
        $deleted = (int) $cheque->deleted === 1;
        $number  = '<bdi dir="ltr">' . esc($this->numberOf($cheque)) . '</bdi>';
        $link    = $deleted ? $number : anchor("accounting_cheques/view/{$id}", $number);

        return [
            'cheque_id'     => $id,
            'cheque_number' => $link . '<div class="small text-body-secondary"><bdi dir="ltr">' . esc($cheque->cheque_number) . '</bdi></div>',
            'party_name'    => $cheque->party_name,
            'treasury_name' => $cheque->treasury_name,
            'issue_date'    => $this->formatDate($cheque->issue_date),
            'due_date'      => $this->formatDate($cheque->due_date),
            'amount'        => $this->amountCell($this->formatAmount((string) $cheque->amount)),
            'status'        => '<span class="badge text-bg-secondary">' . esc(lang(ChequeStatus::from($cheque->status)->labelKey())) . '</span>',
            'branch_code'   => $cheque->branch_code,
            'edit'          => $deleted ? '' : $this->actions($cheque),
        ];
    }

    private function actions(object $cheque): string
    {
        $id   = (int) $cheque->cheque_id;
        $html = anchor("accounting_cheques/view/{$id}", '<span class="bi bi-eye" aria-hidden="true"></span>', ['title' => lang('Accounting_cheques.open'), 'aria-label' => lang('Accounting_cheques.open')]);

        if (! $this->can(AccountingGuard::CHEQUES_MANAGE)) {
            return $html;
        }

        $icons = ['deposit' => 'bi-bank', 'collect' => 'bi-cash-coin', 'reject' => 'bi-x-octagon', 'deliver' => 'bi-send'];

        foreach ($this->cheques->actionsOf($cheque) as $action) {
            $label = esc(lang('Accounting_cheques.action_' . $action), 'attr');

            if (isset($icons[$action])) {
                $html .= anchor("accounting_cheques/action_form/{$action}/{$id}", '<span class="bi ' . $icons[$action] . '" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => $label, 'aria-label' => $label]);
            } elseif ($action === 'edit') {
                $html .= anchor("accounting_cheques/form/{$cheque->direction}/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => $label, 'aria-label' => $label]);
            } else {
                $html .= '<button type="button" class="btn btn-link p-0 acc-action' . ($action === 'delete' ? ' text-danger' : '') . '" data-acc-action="' . $action . '" data-acc-id="' . $id . '" title="' . $label . '" aria-label="' . $label . '"><span class="bi ' . ['undo' => 'bi-arrow-counterclockwise', 'cancel' => 'bi-slash-circle', 'delete' => 'bi-trash'][$action] . '" aria-hidden="true"></span></button>';
            }
        }

        return $html;
    }
}
