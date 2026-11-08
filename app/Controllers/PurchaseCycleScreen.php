<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\PurchaseCycle\PurchaseCycleSettings;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use App\Models\Stock_location;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * The screens of one family of the purchase cycle: a list with its search and status filter, the form, the page of
 * the document with its status actions and its conversion, and the switches of the cycle for who may change them.
 * Every change is a POST that carries the token of the form and that the service may refuse in words.
 */
abstract class PurchaseCycleScreen extends DocumentScreen
{
    protected PurchaseDocuments $documents;
    protected PurchaseCycleSettings $settings;

    public function __construct(protected readonly PurchaseDocumentKind $kind)
    {
        parent::__construct($kind->module());

        $this->documents = new PurchaseDocuments($kind, null, null, $this->branchGuard());
        $this->settings  = new PurchaseCycleSettings();
    }

    public function getIndex(): string
    {
        $filters = $this->filters();
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $enabled = $this->documents->isEnabled();
        $result  = $this->documents->search($filters, $this->employeeId(), self::PAGE_SIZE, $offset);

        return view('purchase_cycle/manage', [
            'kind'        => $this->kind,
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'offset'      => $offset,
            'pageSize'    => self::PAGE_SIZE,
            'filters'     => $filters,
            'enabled'     => $enabled,
            'settings'    => $this->settings->current(),
            'canAdd'      => $this->can($this->kind->permission(PurchaseDocuments::ADD)),
            'canSettings' => $this->can(PurchaseCycleSettings::PERMISSION),
            'statuses'    => $this->statusFilterOptions(),
        ]);
    }

    public function getForm(int $documentId = NEW_ENTRY): RedirectResponse|string
    {
        if (! $this->documents->isEnabled()) {
            return redirect()->to(site_url($this->kind->module()));
        }

        $document = null;
        $lines    = [];

        if ($documentId !== NEW_ENTRY) {
            $document = $this->documents->find($documentId, $this->employeeId());

            if ($document === null || ! $document->status_object->isEditable() || ! $this->can($this->kind->permission(PurchaseDocuments::EDIT))) {
                throw PageNotFoundException::forPageNotFound();
            }

            $lines = $this->documents->lines($documentId);
        } elseif (! $this->can($this->kind->permission(PurchaseDocuments::ADD))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('purchase_cycle/form', [
            'kind'      => $this->kind,
            'document'  => $document,
            'lines'     => $lines,
            'locations' => $this->receivingLocations(),
            'number'    => $document === null ? $this->documents->peekNumber() : $this->documents->numberOf($documentId),
            'today'     => date('Y-m-d'),
        ]);
    }

    public function getShow(int $documentId): string
    {
        $document = $this->documents->isEnabled() ? $this->documents->find($documentId, $this->employeeId()) : null;

        if ($document === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $status    = $document->status_object;
        $receiving = $this->documents->receivings($documentId);
        $next      = $this->kind->next();
        $manual    = $this->documents->allowsManualStatus();

        return view('purchase_cycle/view', [
            'kind'       => $this->kind,
            'document'   => $document,
            'status'     => $status,
            'number'     => $this->documents->numberOf($documentId),
            'lines'      => $this->documents->lines($documentId),
            'madeFrom'   => $this->documents->madeFrom($documentId),
            'madeOf'     => $this->documents->madeOf($document),
            'receivings' => $receiving,
            'canEdit'    => $this->can($this->kind->permission(PurchaseDocuments::EDIT)) && $status->isEditable(),
            'canStatus'  => $manual && $this->can($this->kind->permission(PurchaseDocuments::EDIT)) && $status->manualNext() !== [],
            'canCancel'  => $this->can($this->kind->permission(PurchaseDocuments::DELETE)) && $status->canCancel(),
            'canConvert' => $this->can($this->kind->permission(PurchaseDocuments::CONVERT)) && $status->canConvert($this->kind),
            'next'       => $status->manualNext(),
            'nextKind'   => $next,
        ]);
    }

    public function getSuppliers(): ResponseInterface
    {
        if (! $this->kind->hasSupplier() || ! ($this->can($this->kind->permission(PurchaseDocuments::ADD)) || $this->can($this->kind->permission(PurchaseDocuments::EDIT)))) {
            return $this->forbidden();
        }

        $builder = Database::connect()->table('suppliers s')
            ->select("s.person_id, COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)
            ->join('people p', 'p.person_id = s.person_id')
            ->where('s.deleted', 0);

        $term = $this->getText('term');

        if ($term !== '') {
            $builder->groupStart()->like('s.company_name', $term)->orLike('p.first_name', $term)->orLike('p.last_name', $term)->groupEnd();
        }

        $rows = $builder->orderBy('s.company_name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->person_id, 'label' => $row->name], $rows));
    }

    public function getItems(): ResponseInterface
    {
        if (! ($this->can($this->kind->permission(PurchaseDocuments::ADD)) || $this->can($this->kind->permission(PurchaseDocuments::EDIT)))) {
            return $this->forbidden();
        }

        $builder = Database::connect()->table('items')->select('item_id, name, item_number, cost_price')->where('deleted', 0);
        $term    = $this->getText('term');

        if ($term !== '') {
            $builder->groupStart()->like('name', $term)->orLike('item_number', $term)->groupEnd();
        }

        $rows = $builder->orderBy('name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->item_id, 'label' => $row->name . ($row->item_number === null || $row->item_number === '' ? '' : ' (' . $row->item_number . ')'), 'price' => (string) $row->cost_price], $rows));
    }

    public function postSave(int $documentId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($documentId): array {
            $input = [
                'supplier_id'   => (int) $this->request->getPost('supplier_id'),
                'location_id'   => (int) $this->request->getPost('location_id'),
                'document_date' => $this->postText('document_date'),
                'due_date'      => $this->postText('due_date'),
                'note'          => $this->postText('note'),
                'lines'         => $this->request->getPost('lines'),
            ];

            if ($documentId === NEW_ENTRY) {
                $documentId = $this->documents->create($input, $this->employeeId());

                return ['success' => true, 'message' => lang('Purchase_cycle.successful_adding'), 'id' => $documentId, 'redirect' => site_url($this->kind->module() . '/view/' . $documentId)];
            }

            $this->documents->update($documentId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Purchase_cycle.successful_updating'), 'id' => $documentId, 'redirect' => site_url($this->kind->module() . '/view/' . $documentId)];
        });
    }

    public function postStatus(int $documentId): ResponseInterface
    {
        return $this->attempt(function () use ($documentId): array {
            $to = PurchaseDocumentStatus::tryFrom($this->postText('status'));

            if ($to === null || ! $to->isLabel()) {
                throw new AccountingException(AccountingError::PurchaseDocumentStatusNotAllowed, 'The status is not one that is set by hand.', ['from' => '', 'to' => $this->postText('status')]);
            }

            $this->documents->changeStatus($documentId, $to, $this->employeeId());

            return ['success' => true, 'message' => lang('Purchase_cycle.status_changed'), 'id' => $documentId];
        });
    }

    public function postCancel(int $documentId): ResponseInterface
    {
        return $this->attempt(function () use ($documentId): array {
            $this->documents->cancel($documentId, $this->postText('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Purchase_cycle.successful_cancelling'), 'id' => $documentId];
        });
    }

    public function postConvert(int $documentId): ResponseInterface
    {
        return $this->attempt(function () use ($documentId): array {
            $newId = $this->documents->convert($documentId, $this->employeeId());
            $next  = $this->kind->next();

            return ['success' => true, 'message' => lang('Purchase_cycle.converted'), 'id' => $newId, 'redirect' => site_url($next->module() . '/view/' . $newId)];
        });
    }

    public function postSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $manual = [];

            foreach (PurchaseDocumentKind::cases() as $kind) {
                $manual[$kind->value] = $this->request->getPost('manual_' . $kind->value) === '1';
            }

            $this->settings->update([
                'enabled'      => $this->request->getPost('enabled') === '1',
                'allow_excess' => $this->request->getPost('allow_excess') === '1',
                'manual'       => $manual,
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Purchase_cycle.settings_saved'), 'id' => NEW_ENTRY];
        });
    }

    /**
     * Stock locations the employee works in at the receiving, by id: where the goods of the document are wanted.
     *
     * @return array<int, string>
     */
    protected function receivingLocations(): array
    {
        $options = [];

        foreach (model(Stock_location::class)->get_allowed_locations('receivings', true) as $id => $name) {
            $options[(int) $id] = (string) $name;
        }

        return $options;
    }

    /**
     * @return list<PurchaseDocumentStatus>
     */
    private function statusFilterOptions(): array
    {
        $statuses = [PurchaseDocumentStatus::Open, PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved, PurchaseDocumentStatus::Converted];

        if ($this->kind === PurchaseDocumentKind::Order) {
            $statuses = [PurchaseDocumentStatus::Open, PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved, PurchaseDocumentStatus::PartlyReceived, PurchaseDocumentStatus::Received];
        }

        return [...$statuses, PurchaseDocumentStatus::Cancelled];
    }

    /**
     * @return array{status: string, search: string, from: string, to: string, supplier_id: int}
     */
    private function filters(): array
    {
        $status = $this->getText('status');

        return [
            'status'      => PurchaseDocumentStatus::tryFrom($status) !== null ? $status : '',
            'search'      => $this->getText('search'),
            'from'        => $this->dateOf('from'),
            'to'          => $this->dateOf('to'),
            'supplier_id' => max(0, (int) $this->getText('supplier_id')),
        ];
    }
}
