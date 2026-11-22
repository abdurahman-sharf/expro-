<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\Organization\BranchException;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Libraries\Tax\VatClassification;
use App\Libraries\Tax\VatClassificationException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * The VAT classes of the company with their rates by date, and the classification of the items, under the taxes
 * module the tax list already uses.
 */
class Vat_classes extends AccountingController
{
    private VatClassification $classes;

    public function __construct()
    {
        parent::__construct(VatClassification::TAXES_PERMISSION, false);

        $this->classes = new VatClassification();
    }

    public function getIndex(): string
    {
        $categories = [];

        foreach ($this->db()->table('items')->select('category')->distinct()->where('deleted', 0)->where('category !=', '')->orderBy('category')->get()->getResult() as $row) {
            $categories[] = (string) $row->category;
        }

        return view('vat_classes/manage', [
            'classes'     => $this->classes->classes(),
            'exemptions'  => EInvoiceSettings::EXEMPTION_CODES,
            'unclassed'   => $this->classes->unclassifiedCount(),
            'conflicting' => $this->classes->ambiguousCount(),
            'categories'  => $categories,
            'saudiMode'   => (new CompanyTaxProfile())->isSaudiMode(),
            'today'       => date('Y-m-d'),
        ]);
    }

    public function postSaveClass(): ResponseInterface
    {
        return $this->respond(function (): string {
            $classId = (int) $this->request->getPost('class_id');
            $input   = [];

            foreach (['code', 'name', 'category', 'exemption_code', 'exemption_text', 'tax_name', 'percent', 'valid_from'] as $field) {
                if ($this->request->getPost($field) !== null) {
                    $input[$field] = (string) $this->request->getPost($field);
                }
            }

            if ($this->request->getPost('active') !== null) {
                $input['active'] = $this->request->getPost('active') === '1';
            }

            $this->classes->saveClass($input, $classId > 0 ? $classId : null, $this->employeeId());

            return lang('Vat_classes.class_saved');
        });
    }

    public function postAddRate(int $classId): ResponseInterface
    {
        return $this->respond(function () use ($classId): string {
            $this->classes->addRate($classId, (string) $this->request->getPost('percent'), (string) $this->request->getPost('valid_from'), $this->employeeId());

            return lang('Vat_classes.rate_added');
        });
    }

    public function postClassify(): ResponseInterface
    {
        return $this->respond(function (): string {
            $classId = (int) $this->request->getPost('class_id');
            $count   = $this->request->getPost('scope') === 'category'
                ? $this->classes->classifyByCategory((string) $this->request->getPost('category'), $classId, $this->employeeId())
                : $this->classes->classifyUnclassifiedWithOneTax($classId, $this->employeeId());

            return lang('Vat_classes.items_classified', [$count]);
        });
    }

    /**
     * @param callable(): string $action
     */
    private function respond(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON(['success' => true, 'message' => $action()]);
        } catch (VatClassificationException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $exception->describe()]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception)]);
        }
    }

    private function db(): BaseConnection
    {
        return Database::connect();
    }
}
