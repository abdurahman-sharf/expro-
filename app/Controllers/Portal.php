<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Documents\RendersPdf;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalDocuments;
use App\Libraries\Portal\PortalLogin;
use App\Libraries\Portal\PortalLoginOutcome;
use App\Libraries\Portal\PortalSession;
use App\Libraries\Portal\PortalStatement;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The read-only portal of a customer: the sign-in, the change of the password, and the statement, the
 * invoices, the credit notes and the payments of the customer who is signed in, with a page and a PDF file
 * of each document. Who may come in is decided by the PortalGuard filter before any of this runs; what a
 * page shows is decided by the customer of the session and never by anything in the request, and a number
 * in an address that is not a document of that customer is a page that does not exist.
 */
class Portal extends BaseController
{
    use RendersPdf;

    public function getIndex(): RedirectResponse
    {
        return redirect()->to(site_url('portal/statement'));
    }

    public function getLogin(): string|RedirectResponse
    {
        if ((new PortalSession())->customerId() !== null) {
            return redirect()->to(site_url('portal/statement'));
        }

        return view('portal/login', ['signedIn' => false]);
    }

    public function postLogin(): ResponseInterface|RedirectResponse
    {
        $this->requirePost();

        $session = new PortalSession();

        if ($session->customerId() !== null) {
            return redirect()->to(site_url('portal/statement'));
        }

        $result = (new PortalLogin())->attempt($this->text('username'), $this->text('password'), $this->request->getIPAddress());

        if ($result->outcome === PortalLoginOutcome::Success) {
            $session->begin($result->account);

            return redirect()->to(site_url('portal/statement'));
        }

        $locked = $result->outcome === PortalLoginOutcome::Locked;

        return $this->response
            ->setStatusCode($locked ? 429 : 401)
            ->setBody(view('portal/login', ['signedIn' => false, 'error' => lang($locked ? 'Portal.too_many_attempts' : 'Portal.invalid_login')]));
    }

    public function postLogout(): RedirectResponse
    {
        $this->requirePost();

        (new PortalSession())->end();

        return redirect()->to(site_url('portal/login'));
    }

    public function getPassword(): string
    {
        return view('portal/password', ['signedIn' => true, 'forced' => $this->mustChange()]);
    }

    public function postPassword(): ResponseInterface|RedirectResponse
    {
        $this->requirePost();

        $session   = new PortalSession();
        $accountId = $session->accountId() ?? throw PageNotFoundException::forPageNotFound();
        $new       = $this->text('new_password');

        try {
            if ($new !== $this->text('confirm_password')) {
                return $this->response->setStatusCode(422)->setBody(view('portal/password', ['signedIn' => true, 'forced' => $this->mustChange(), 'error' => lang('Portal.password_mismatch')]));
            }

            $version = (new PortalAccounts())->changePassword($accountId, $this->text('current_password'), $new);
        } catch (AccountingException $failure) {
            return $this->response->setStatusCode(422)->setBody(view('portal/password', ['signedIn' => true, 'forced' => $this->mustChange(), 'error' => AccountingMessage::of($failure)]));
        }

        $session->renew($version);

        return redirect()->to(site_url('portal/statement'));
    }

    public function getStatement(): string
    {
        return view('portal/statement', ['signedIn' => true, 'statement' => (new PortalStatement())->of($this->customerId())]);
    }

    public function getInvoices(): string
    {
        $page = $this->page();

        return view('portal/invoices', ['signedIn' => true, 'page' => $page, 'found' => (new PortalDocuments())->invoices($this->customerId(), $page)]);
    }

    public function getCreditNotes(): string
    {
        $page = $this->page();

        return view('portal/credit_notes', ['signedIn' => true, 'page' => $page, 'found' => (new PortalDocuments())->creditNotes($this->customerId(), $page)]);
    }

    public function getPayments(): string
    {
        $page = $this->page();

        return view('portal/payments', ['signedIn' => true, 'page' => $page, 'found' => (new PortalDocuments())->payments($this->customerId(), $page)]);
    }

    public function getInvoice(int $saleId): string
    {
        $documents = new PortalDocuments();
        $doc       = $documents->invoice($this->customerId(), $saleId) ?? throw PageNotFoundException::forPageNotFound();

        return view('portal/document', ['signedIn' => true, 'doc' => $doc, 'customerName' => $documents->customerName($this->customerId()), 'pdfPath' => 'portal/invoice/' . $saleId . '/pdf', 'backPath' => 'portal/invoices']);
    }

    public function getInvoicePdf(int $saleId): ResponseInterface
    {
        $documents = new PortalDocuments();
        $doc       = $documents->invoice($this->customerId(), $saleId) ?? throw PageNotFoundException::forPageNotFound();

        return $this->pdfResponse($this->response, view('portal/document_pdf', ['doc' => $doc, 'customerName' => $documents->customerName($this->customerId())]), $doc['title'], $doc['number']);
    }

    public function getCreditNote(int $saleId): string
    {
        $documents = new PortalDocuments();
        $doc       = $documents->creditNote($this->customerId(), $saleId) ?? throw PageNotFoundException::forPageNotFound();

        return view('portal/document', ['signedIn' => true, 'doc' => $doc, 'customerName' => $documents->customerName($this->customerId()), 'pdfPath' => 'portal/credit_note/' . $saleId . '/pdf', 'backPath' => 'portal/credit_notes']);
    }

    public function getCreditNotePdf(int $saleId): ResponseInterface
    {
        $documents = new PortalDocuments();
        $doc       = $documents->creditNote($this->customerId(), $saleId) ?? throw PageNotFoundException::forPageNotFound();

        return $this->pdfResponse($this->response, view('portal/document_pdf', ['doc' => $doc, 'customerName' => $documents->customerName($this->customerId())]), $doc['title'], $doc['number']);
    }

    public function getPayment(int $paymentId): string
    {
        $voucher = (new PortalDocuments())->payment($this->customerId(), $paymentId) ?? throw PageNotFoundException::forPageNotFound();

        return view('portal/receipt', ['signedIn' => true, 'voucher' => $voucher, 'pdfPath' => 'portal/payment/' . $paymentId . '/pdf', 'backPath' => 'portal/payments']);
    }

    public function getPaymentPdf(int $paymentId): ResponseInterface
    {
        $voucher = (new PortalDocuments())->payment($this->customerId(), $paymentId) ?? throw PageNotFoundException::forPageNotFound();

        return $this->pdfResponse($this->response, view('documents/voucher_pdf', ['voucher' => $voucher]), $voucher->title, $voucher->number);
    }

    private function customerId(): int
    {
        return (new PortalSession())->customerId() ?? throw PageNotFoundException::forPageNotFound();
    }

    private function mustChange(): bool
    {
        $accountId = (new PortalSession())->accountId();

        return $accountId !== null && (int) ((new PortalAccounts())->find($accountId)->must_change ?? 0) === 1;
    }

    private function text(string $field): string
    {
        $value = $this->request->getPost($field);

        return is_string($value) ? $value : '';
    }

    private function page(): int
    {
        return max(1, (int) $this->request->getGet('page'));
    }

    private function requirePost(): void
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
