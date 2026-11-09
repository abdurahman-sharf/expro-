<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\EInvoicing\EInvoiceChoices;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceReadiness;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\PartyIdentificationType;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The screens of the candidate document layer of electronic invoicing: its settings, its readiness, the
 * documents that were issued and their files. Changing the settings, issuing a document and taking a
 * file need the sensitive permission and, for the settings, every branch. Nothing here sends anything to
 * an outside service.
 */
class AccountingEInvoicing extends AccountingController
{
    private const DOCUMENTS_PAGE = 50;
    private const CUSTOMERS_PAGE = 25;

    private EInvoiceSettings $settings;
    private EInvoiceService $service;
    private EInvoiceReadiness $readiness;
    private EInvoiceChoices $choices;

    public function __construct()
    {
        parent::__construct('accounting_einvoicing');

        $this->settings  = new EInvoiceSettings(null, null, $this->branchGuard());
        $this->service   = new EInvoiceService(null, null, $this->branchGuard());
        $this->readiness = new EInvoiceReadiness(null, $this->settings);
        $this->choices   = new EInvoiceChoices(null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $canManage   = $this->canManage(AccountingGuard::EINVOICING_MANAGE);
        $canDownload = $this->can(AccountingGuard::EINVOICING_MANAGE);
        $values      = $this->settings->all();
        $documents   = $this->documentsPage();
        $customers   = $this->customersPage();
        $notes       = $canDownload ? $this->choices->notesWaiting($this->employeeId()) : [];
        $originals   = $this->choices->originalsOf(array_column($notes, 'customer_id'), $this->employeeId());

        if (! $canManage) {
            $values['key_path']         = '';
            $values['certificate_path'] = '';
        }

        return view('accounting_einvoicing/manage', [
            'enabled'         => $this->settings->stored(),
            'active'          => $this->settings->isEnabled(),
            'values'          => $values,
            'seller_name'     => $this->settings->sellerName(),
            'seller_vat'      => $this->settings->sellerVatNumber(),
            'seller_types'    => PartyIdentificationType::forSeller(),
            'exemptions'      => EInvoiceSettings::EXEMPTION_CODES,
            'checks'          => $this->readiness->checks(),
            'customers'       => $customers['summary'],
            'customers_pager' => $customers['pager'],
            'documents'       => $documents['rows'],
            'documents_pager' => $documents['pager'],
            'notes'           => array_map(fn (array $note): array => [...$note, 'total' => $this->formatAmount($note['total']), 'date' => $this->formatDate($note['date'])], $notes),
            'originals'       => $originals,
            'can_manage'      => $canManage,
            'can_download'    => $canDownload,
            'reasons'         => EInvoiceRules::NOTE_REASONS,
        ]);
    }

    public function postSaveSettings(): ResponseInterface
    {
        return $this->respond(function (): array {
            $input = [];

            foreach (array_keys(EInvoiceSettings::FIELDS) as $field) {
                if ($this->request->getPost($field) !== null) {
                    $input[$field] = (string) $this->request->getPost($field);
                }
            }

            $input['enabled'] = $this->request->getPost('enabled') === '1';

            $changes = $this->settings->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang($changes === [] ? 'Accounting_einvoicing.settings_unchanged' : 'Accounting_einvoicing.settings_saved'), 'id' => 0];
        });
    }

    public function postIssue(int $saleId): ResponseInterface
    {
        return $this->respond(function () use ($saleId): array {
            $subtype  = EInvoiceSubtype::tryFrom((string) $this->request->getPost('subtype'));
            $document = $this->service->issueSale($saleId, $subtype, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_einvoicing.issued', [$document->number, $document->icv]), 'id' => $document->documentId];
        });
    }

    public function postIssueDebitNote(int $noteId): ResponseInterface
    {
        return $this->respond(function () use ($noteId): array {
            $document = $this->service->issueDebitNote($noteId, (string) $this->request->getPost('reason'), (int) $this->request->getPost('original_document_id'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_einvoicing.issued', [$document->number, $document->icv]), 'id' => $document->documentId];
        });
    }

    public function getXml(int $documentId): ResponseInterface
    {
        try {
            $document = $this->service->xml($documentId, $this->employeeId());
        } catch (AccountingException|BranchException|EInvoiceException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType('application/xml; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $document->fileName($this->settings->sellerVatNumber()) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($document->xml);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, pager: array{total: int, offset: int, size: int}}
     */
    private function documentsPage(): array
    {
        $branchIds = $this->branchIds();
        $viewerId  = $this->employeeId();
        $total     = $this->service->countIssued($branchIds, $viewerId);
        $offset    = $this->offsetWithin('documents_offset', $total, self::DOCUMENTS_PAGE);
        $rows      = [];

        foreach ($this->service->issuedIn($branchIds, self::DOCUMENTS_PAGE, $viewerId, $offset) as $row) {
            $rows[] = [
                'document_id' => (int) $row->document_id,
                'number'      => (string) $row->document_number,
                'kind'        => EInvoiceKind::from((string) $row->doc_kind),
                'subtype'     => EInvoiceSubtype::from((string) $row->subtype),
                'icv'         => (int) $row->icv,
                'date'        => $this->formatDate((string) $row->issue_date),
                'total'       => $this->formatAmount((string) $row->tax_inclusive),
                'signed'      => (int) $row->signed === 1,
                'submission'  => $this->service->submissionOf((int) $row->document_id),
            ];
        }

        return ['rows' => $rows, 'pager' => ['total' => $total, 'offset' => $offset, 'size' => self::DOCUMENTS_PAGE]];
    }

    /**
     * @return array{summary: array<string, mixed>, pager: array{total: int, offset: int, size: int}}
     */
    private function customersPage(): array
    {
        $total   = $this->readiness->customersWithoutIdentification(1)['total'];
        $offset  = $this->offsetWithin('customers_offset', $total, self::CUSTOMERS_PAGE);
        $summary = $this->readiness->customersWithoutIdentification(self::CUSTOMERS_PAGE, $offset);

        return ['summary' => $summary, 'pager' => ['total' => $total, 'offset' => $offset, 'size' => self::CUSTOMERS_PAGE]];
    }

    private function offsetWithin(string $name, int $total, int $size): int
    {
        $offset = max(0, (int) $this->request->getGet($name));

        return $offset < $total ? intdiv($offset, $size) * $size : max(0, intdiv($total - 1, $size) * $size);
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function respond(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (EInvoiceException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $exception->describe(), 'id' => NEW_ENTRY]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }
    }
}
