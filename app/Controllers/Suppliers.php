<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\MasterData\CustomerFields;
use App\Libraries\MasterData\PartyContacts;
use App\Libraries\MasterData\PartyLengths;
use App\Models\Supplier;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class Suppliers extends Persons
{
    private Supplier $supplier;

    public function __construct()
    {
        parent::__construct('suppliers');

        $this->supplier = model(Supplier::class);
    }

    public function getIndex(): string
    {
        $data['table_headers'] = get_suppliers_manage_table_headers();

        return view('people/manage', $data);
    }

    /**
     * Gets one row for a supplier manage table. This is called using AJAX to update one row.
     */
    public function getRow($row_id): ResponseInterface
    {
        $data_row             = get_supplier_data_row($this->supplier->get_info($row_id));
        $data_row['category'] = $this->supplier->get_category_name($data_row['category']);

        return $this->response->setJSON($data_row);
    }

    /**
     * Returns Supplier table data rows. This will be called with AJAX.
     *
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit  = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort   = $this->sanitizeSortColumn(supplier_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'people.person_id');
        $order  = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $suppliers  = $this->supplier->search($search, $limit, $offset, $sort, $order);
        $total_rows = $this->supplier->get_found_rows($search);

        $data_rows = [];

        foreach ($suppliers->getResult() as $supplier) {
            $row             = get_supplier_data_row($supplier);
            $row['category'] = $this->supplier->get_category_name($row['category']);
            $data_rows[]     = $row;
        }

        return $this->response->setJSON(['total' => $total_rows, 'rows' => $data_rows]);
    }

    /**
     * Gives search suggestions based on what is being searched for
     */
    public function getSuggest(): ResponseInterface
    {
        $search      = $this->request->getGet('term');
        $suggestions = $this->supplier->get_search_suggestions($search, true);

        return $this->response->setJSON($suggestions);
    }

    public function suggest_search(): ResponseInterface
    {
        $search      = $this->request->getPost('term');
        $suggestions = $this->supplier->get_search_suggestions($search, false);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Loads the supplier edit form
     */
    public function getView(int $supplier_id = NEW_ENTRY): string
    {
        $info = $this->supplier->get_info($supplier_id);

        foreach (get_object_vars($info) as $property => $value) {
            $info->{$property} = $value;
        }
        $data['person_info'] = $info;
        $data['categories']  = $this->supplier->get_categories();
        $data['contacts']    = $supplier_id === NEW_ENTRY ? [] : (new PartyContacts())->forParty(PartyContacts::SUPPLIER, (int) $supplier_id);

        return view('suppliers/form', $data);
    }

    /**
     * Inserts/updates a supplier
     */
    public function postSave(int $supplierId = NEW_ENTRY): ResponseInterface
    {
        $first_name = $this->request->getPost('first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);    // TODO: Duplicate code
        $last_name  = $this->request->getPost('last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email      = strtolower($this->request->getPost('email', FILTER_SANITIZE_EMAIL));

        // Format first and last name properly
        $first_name = $this->nameize($first_name);
        $last_name  = $this->nameize($last_name);

        $personData = [
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'gender'       => $this->request->getPost('gender'),
            'email'        => $email,
            'phone_number' => $this->request->getPost('phone_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'address_1'    => $this->request->getPost('address_1', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'address_2'    => $this->request->getPost('address_2', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'city'         => $this->request->getPost('city', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'state'        => $this->request->getPost('state', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'zip'          => $this->request->getPost('zip', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'country'      => $this->request->getPost('country', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'comments'     => $this->request->getPost('comments', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
        ];

        $supplierData = [
            'company_name'   => $this->request->getPost('company_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'agency_name'    => $this->request->getPost('agency_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'category'       => $this->request->getPost('category', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'account_number' => $this->request->getPost('account_number') === '' ? null : $this->request->getPost('account_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'tax_id'         => $this->request->getPost('tax_id', FILTER_SANITIZE_NUMBER_INT),
        ];

        $current = $supplierId === NEW_ENTRY || ! $this->supplier->exists((int) $supplierId) ? null : $this->supplier->get_info((int) $supplierId);

        try {
            PartyLengths::check(
                ['company_name' => (string) $this->request->getPost('company_name'), 'city' => (string) $this->request->getPost('city'), 'state' => (string) $this->request->getPost('state'), 'zip' => (string) $this->request->getPost('zip')],
                $current,
                ['company_name' => lang('Suppliers.company_name'), 'city' => lang('Common.city'), 'state' => lang('Common.state'), 'zip' => lang('Common.zip')],
            );

            if ($this->request->getPost('country_code') !== null) {
                $supplierData['country_code'] = CustomerFields::countryCode(trim((string) $this->request->getPost('country_code')));
            }
        } catch (AccountingException $failure) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($failure), 'id' => NEW_ENTRY]);
        }

        if ($this->request->getPost('commercial_registry') !== null) {
            $supplierData['commercial_registry'] = mb_substr(trim((string) $this->request->getPost('commercial_registry', FILTER_SANITIZE_FULL_SPECIAL_CHARS)), 0, 64);
        }

        $contacts    = new PartyContacts();
        $contactRows = PartyContacts::fromRequest($this->request->getPost());

        try {
            $contactRows = $contactRows === null ? null : $contacts->prepare(PartyContacts::SUPPLIER, $current === null ? null : (int) $supplierId, $contactRows);
        } catch (AccountingException $failure) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($failure), 'id' => NEW_ENTRY]);
        }

        if ($this->supplier->save_supplier($personData, $supplierData, $supplierId)) {
            $contactNote = '';

            if ($contactRows !== null) {
                try {
                    $contacts->replace(PartyContacts::SUPPLIER, (int) ($supplierData['person_id'] ?? $supplierId), $contactRows, (int) $this->employee->get_logged_in_employee_info()->person_id);
                } catch (AccountingException $failure) {
                    $contactNote = ' ' . AccountingMessage::of($failure);
                }
            }

            // New supplier
            if ($supplierId === NEW_ENTRY) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Suppliers.successful_adding') . ' ' . $supplierData['company_name'] . $contactNote,
                    'id'      => $supplierData['person_id'],
                ]);
            }   // Existing supplier

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Suppliers.successful_updating') . ' ' . $supplierData['company_name'] . $contactNote,
                'id'      => $supplierId,
            ]);
        }   // Failure

        return $this->response->setJSON([
            'success' => false,
            'message' => lang('Suppliers.error_adding_updating') . ' ' . $supplierData['company_name'],
            'id'      => NEW_ENTRY,
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function getContacts(int $supplierId): ResponseInterface
    {
        if (! $this->supplier->exists($supplierId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON(['contacts' => array_map(static fn (object $contact): array => [
            'contact_id' => (int) $contact->contact_id,
            'first_name' => $contact->first_name,
            'last_name'  => $contact->last_name,
            'email'      => $contact->email,
            'phone'      => $contact->phone,
            'mobile'     => $contact->mobile,
            'position'   => $contact->position,
            'note'       => $contact->note,
        ], (new PartyContacts())->forParty(PartyContacts::SUPPLIER, $supplierId))]);
    }

    /**
     * This deletes suppliers from the suppliers table
     */
    public function postDelete(): ResponseInterface
    {
        $suppliers_to_delete = $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT);

        if ($this->supplier->delete_list($suppliers_to_delete)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Suppliers.successful_deleted') . ' ' . count($suppliers_to_delete) . ' ' . lang('Suppliers.one_or_multiple'),
            ]);
        }

        return $this->response->setJSON(['success' => false, 'message' => lang('Suppliers.cannot_be_deleted')]);
    }
}
