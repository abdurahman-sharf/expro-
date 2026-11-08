<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
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
    private EInvoiceSettings $settings;
    private EInvoiceService $service;
    private EInvoiceReadiness $readiness;

    public function __construct()
    {
        parent::__construct('accounting_einvoicing');

        $this->settings  = new EInvoiceSettings(null, null, $this->branchGuard());
        $this->service   = new EInvoiceService(null, null, $this->branchGuard());
        $this->readiness = new EInvoiceReadiness(null, $this->settings);
    }

    public function getIndex(): string
    {
        $canManage = $this->canManage(AccountingGuard::EINVOICING_MANAGE);

        return view('accounting_einvoicing/manage', [
            'enabled'      => $this->settings->stored(),
            'active'       => $this->settings->isEnabled(),
            'values'       => $this->settings->all(),
            'seller_name'  => $this->settings->sellerName(),
            'seller_vat'   => $this->settings->sellerVatNumber(),
            'seller_types' => PartyIdentificationType::forSeller(),
            'exemptions'   => EInvoiceSettings::EXEMPTION_CODES,
            'checks'       => $this->readiness->checks(),
            'customers'    => $this->readiness->customersWithoutIdentification(25),
            'documents'    => $this->documents(),
            'can_manage'   => $canManage,
            'can_download' => $this->can(AccountingGuard::EINVOICING_MANAGE),
            'reasons'      => EInvoiceRules::NOTE_REASONS,
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

    public function getCustomers(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: 25, 200));
        $offset = max(0, (int) $this->request->getGet('offset'));

        return $this->response->setJSON($this->readiness->customersWithoutIdentification($limit, $offset));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(): array
    {
        $rows = [];

        foreach ($this->service->issuedIn($this->branchIds(), 50) as $row) {
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

        return $rows;
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
