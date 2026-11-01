<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\SalesDebitNotes;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * The sales debit notes: the list of those of the branches the employee works in, the form that raises
 * what a customer owes, and the taking back of a note with a reason. Every change is a POST. A person
 * limited to some branches sees and changes only the notes of those branches.
 */
class AccountingDebitNotes extends AccountingController
{
    private const PAGE_SIZE      = 25;
    private const CUSTOMER_LIMIT = 20;

    private SalesDebitNotes $notes;

    public function __construct()
    {
        parent::__construct('accounting_debit_notes');

        $this->notes = new SalesDebitNotes(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters = $this->filters();
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $result  = $this->notes->search($filters, $this->employeeId(), self::PAGE_SIZE, $offset);

        return view('accounting_debit_notes/manage', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'offset'      => $offset,
            'page_size'   => self::PAGE_SIZE,
            'filters'     => $filters,
            'enabled'     => (new AccountingSettings())->debitNoteEnabled(),
            'can_manage'  => $this->can(AccountingGuard::DEBIT_NOTES_MANAGE),
            'scopeNotice' => $this->scopeNoticeNeeded(),
        ]);
    }

    public function getForm(): ResponseInterface|string
    {
        if (! $this->can(AccountingGuard::DEBIT_NOTES_MANAGE)) {
            return $this->response->setStatusCode(403);
        }

        return view('accounting_debit_notes/form', [
            'accounts' => $this->notes->allowedAccounts(),
            'number'   => $this->notes->peekNumber(),
            'today'    => date('Y-m-d'),
        ]);
    }

    public function getCustomers(): ResponseInterface
    {
        if (! $this->can(AccountingGuard::DEBIT_NOTES_MANAGE)) {
            return $this->response->setStatusCode(403);
        }

        $builder = $this->db()->table('customers c')
            ->select("c.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = c.person_id')
            ->where('c.deleted', 0);

        $term = trim((string) $this->request->getGet('term'));

        if ($term !== '') {
            $builder->groupStart()->like('p.first_name', $term)->orLike('p.last_name', $term)->orLike("CONCAT(p.first_name, ' ', p.last_name)", $term)->groupEnd();
        }

        $rows = $builder->orderBy('p.first_name', 'ASC')->limit(self::CUSTOMER_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->person_id, 'label' => $row->name], $rows));
    }

    public function postSaveNote(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->notes->create([
                'customer_id' => (int) $this->request->getPost('customer_id'),
                'date'        => trim((string) $this->request->getPost('note_date')),
                'amount'      => trim((string) $this->request->getPost('amount')),
                'tax_percent' => trim((string) $this->request->getPost('tax_percent')),
                'account_id'  => (int) $this->request->getPost('account_id'),
                'note'        => (string) $this->request->getPost('note'),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_debit_notes.successful_adding'), 'id' => $id];
        });
    }

    public function getReverseForm(int $debitNoteId): ResponseInterface|string
    {
        $note = $this->visible($debitNoteId);

        if ($note === null || ! $this->can(AccountingGuard::DEBIT_NOTES_MANAGE)) {
            return $this->response->setStatusCode(403);
        }

        return view('accounting_debit_notes/reverse_form', ['debit_note_id' => $debitNoteId, 'number' => $this->notes->numberOf($debitNoteId)]);
    }

    public function postReverse(int $debitNoteId): ResponseInterface
    {
        return $this->attempt(function () use ($debitNoteId): array {
            $this->notes->reverse($debitNoteId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_debit_notes.successful_reversing'), 'id' => $debitNoteId];
        });
    }

    private function visible(int $debitNoteId): ?object
    {
        $note = $this->notes->find($debitNoteId);

        return $note !== null && $this->branchGuard()->canAccess((int) $note->branch_id, $this->employeeId()) ? $note : null;
    }

    /**
     * @return array{status: string, search: string, from: string, to: string}
     */
    private function filters(): array
    {
        $status = (string) $this->request->getGet('status');

        return [
            'status' => in_array($status, ['posted', 'reversed'], true) ? $status : '',
            'search' => trim((string) $this->request->getGet('search')),
            'from'   => $this->dateOf('from'),
            'to'     => $this->dateOf('to'),
        ];
    }

    private function dateOf(string $name): string
    {
        $value = trim((string) $this->request->getGet($name));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function db(): BaseConnection
    {
        return Database::connect();
    }
}
