<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\PostingException;
use App\Libraries\EInvoicing\CustomerParties;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\EInvoicing\PartyIdentificationType;
use App\Libraries\MasterData\BillingMethod;
use App\Libraries\MasterData\CustomerFields;
use App\Libraries\MasterData\CustomerStatus;
use App\Libraries\MasterData\CustomerType;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use App\Libraries\MasterData\PartyLengths;
use App\Libraries\Mailchimp_lib;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Customer;
use App\Models\Customer_rewards;
use App\Models\Price_list;
use App\Models\Tax_code;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\OSPOS;
use Config\Services;
use stdClass;

class Customers extends Persons
{
    private const IMPORT_BASE_COLUMNS = 18;

    private string $_list_id;
    private Mailchimp_lib $mailchimp_lib;
    private Customer_rewards $customer_rewards;
    private Customer $customer;
    private Price_list $priceList;
    private Tax_code $tax_code;
    private array $config;

    public function __construct()
    {
        parent::__construct('customers');
        $this->mailchimp_lib = new Mailchimp_lib();
        $this->customer_rewards = model(Customer_rewards::class);
        $this->customer = model(Customer::class);
        $this->priceList = model(Price_list::class);
        $this->tax_code = model(Tax_code::class);
        $this->config = config(OSPOS::class)->settings;

        $encrypter = Services::encrypter();

        if (!empty($this->config['mailchimp_list_id'])) {
            $this->_list_id = $encrypter->decrypt($this->config['mailchimp_list_id']);
        } else {
            $this->_list_id = '';
        }
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        $data['table_headers'] = get_customer_manage_table_headers();
        $data['filterOptions'] = $this->filterOptions();

        return view('customers/manage', $data);
    }

    /**
     * What the directory can be narrowed by: the lists to choose from, and the employees the viewer may name.
     *
     * @return array<string, array<int|string, string>>
     */
    private function filterOptions(): array
    {
        return [
            'classifications' => (new NamedLookup(LookupKind::CustomerClassification))->options(),
            'statuses'        => CustomerStatus::options(),
            'employees'       => (new CustomerFields())->assignableEmployees($this->viewerId()),
            'types'           => CustomerType::options(),
        ];
    }

    private function viewerId(): int
    {
        return (int) $this->employee->get_logged_in_employee_info()->person_id;
    }

    /**
     * The branches whose sales the viewer sees in the directory, null for every branch.
     *
     * @return list<int>|null
     */
    private function branchScope(): ?array
    {
        return (new BranchGuard(null, $this->employee))->allowedBranchIds($this->viewerId());
    }

    /**
     * The narrowing the directory was asked for. A value that is not one the lists offer is left out.
     *
     * @return array<string, mixed>
     */
    private function directoryFilters(): array
    {
        $get     = fn (string $name): string => trim((string) $this->request->getGet($name));
        $filters = [];

        if (ctype_digit($get('classification_id')) && (int) $get('classification_id') > 0) {
            $filters['classification_id'] = (int) $get('classification_id');
        }

        if (CustomerStatus::tryFrom($get('manual_status')) !== null) {
            $filters['manual_status'] = $get('manual_status');
        }

        if (CustomerType::tryFrom($get('customer_type')) !== null) {
            $filters['customer_type'] = $get('customer_type');
        }

        if (ctype_digit($get('assigned_employee_id')) && (int) $get('assigned_employee_id') > 0) {
            $filters['assigned_employee_id'] = (int) $get('assigned_employee_id');
        }

        foreach (['area', 'city'] as $name) {
            if ($get($name) !== '' && mb_strlen($get($name)) <= CustomerFields::AREA_MAX) {
                $filters[$name] = $get($name);
            }
        }

        if (preg_match('/^[A-Za-z]{2}$/', $get('country_code')) === 1) {
            $filters['country_code'] = strtoupper($get('country_code'));
        }

        foreach (['created_from', 'created_to'] as $name) {
            $day = date_create_from_format('!Y-m-d', $get($name));

            if ($day !== false && $day->format('Y-m-d') === $get($name)) {
                $filters[$name] = $get($name);
            }
        }

        $scope = $this->branchScope();

        $filters['visible_employee_ids'] = $scope === null ? null : array_keys((new CustomerFields())->assignableEmployees($this->viewerId()));

        return $filters;
    }

    /**
     * Gets one row for a customer manage table. This is called using AJAX to update one row.
     * @return ResponseInterface
     */
    public function getRow(int $rowId): ResponseInterface
    {
        $filters = ['person_id' => $rowId, 'visible_employee_ids' => $this->directoryFilters()['visible_employee_ids']];
        $person  = $this->customer->search('', 1, 0, 'last_name', 'asc', false, $filters)->getRow();

        if ($person === null) {
            return $this->response->setJSON([]);
        }

        // Retrieve the total amount the customer spent so far together with min, max and average values
        $stats = $this->customer->get_stats($person->person_id, $this->branchScope());    // TODO: This and the next 11 lines are duplicated in search().  Extract a method.

        if (empty($stats)) {
            // Create object with empty properties.
            $stats = new stdClass();
            $stats->total = 0;
            $stats->min = 0;
            $stats->max = 0;
            $stats->average = 0;
            $stats->avg_discount = 0;
            $stats->quantity = 0;
        }

        $data_row = get_customer_data_row($person, $stats);

        return $this->response->setJSON($data_row);
    }


    /**
     * Returns customer table data rows. This will be called with AJAX.
     *
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort = $this->sanitizeSortColumn(customer_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'people.person_id');
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $filters = $this->directoryFilters();
        $scope = $this->branchScope();

        $customers = $this->customer->search($search, $limit, $offset, $sort, $order, false, $filters);
        $totalRows = $this->customer->get_found_rows($search, $filters);

        $dataRows = [];

        foreach ($customers->getResult() as $person) {
            // Retrieve the total amount the customer spent so far together with min, max and average values
            $stats = $this->customer->get_stats($person->person_id, $scope);    // TODO: duplicated... see above
            if (empty($stats)) {
                // Create object with empty properties.
                $stats = new stdClass();
                $stats->total = 0;
                $stats->min = 0;
                $stats->max = 0;
                $stats->average = 0;
                $stats->avg_discount = 0;
                $stats->quantity = 0;
            }

            $dataRows[] = get_customer_data_row($person, $stats);
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $dataRows]);
    }

    /**
     * Gives search suggestions based on what is being searched for
     * @return ResponseInterface
     */
    public function getSuggest(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->customer->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     */
    public function suggest_search(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->customer->get_search_suggestions($search, 25, false);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Loads the customer edit form
     * @return string
     */
    public function getView(int $customerId = NEW_ENTRY): string
    {
        // Set default values
        if ($customerId == null) $customerId = NEW_ENTRY;

        $info = $this->customer->get_info($customerId);
        foreach (get_object_vars($info) as $property => $value) {
            $info->$property = $value;
        }
        $data['person_info'] = $info;

        $data['automaticAccountNumber'] = $customerId == NEW_ENTRY ? (new DocumentNumbering())->peek(DocumentType::Customer) : null;

        if (empty($info->person_id) || empty($info->date) || empty($info->employee_id)) {
            $data['person_info']->date = date('Y-m-d H:i:s');
            $data['person_info']->employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        }

        $employeeInfo = $this->employee->get_info($info->employee_id);
        $data['employee'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;

        $taxCodeInfo = $this->tax_code->get_info($info->sales_tax_code_id);

        if ($taxCodeInfo->tax_code != null) {
            $data['sales_tax_code_label'] = $taxCodeInfo->tax_code . ' ' . $taxCodeInfo->tax_code_name;
        } else {
            $data['sales_tax_code_label'] = '';
        }

        $packages = ['' => lang('Items.none')];
        foreach ($this->customer_rewards->get_all()->getResultArray() as $row) {
            $packages[$row['package_id']] = $row['package_name'];
        }
        $data['packages'] = $packages;
        $data['selected_package'] = $info->package_id;

        $data = [...$data, ...$this->pricingFormData($info), ...$this->masterDataFormData($info), ...$this->eInvoiceFormData($info)];

        $data['use_destination_based_tax'] = $this->config['use_destination_based_tax'];
        $data['opening_offered']           = $customerId == NEW_ENTRY && $this->openingOffered();
        $data['opening_today']             = date('Y-m-d');

        // Retrieve the total amount the customer spent so far together with min, max and average values
        $stats = $this->customer->get_stats($customerId, $this->branchScope());
        if (!empty($stats)) {
            foreach (get_object_vars($stats) as $property => $value) {
                $info->$property = $value;
            }
            $data['stats'] = $stats;
        }

        // Retrieve the info from Mailchimp only if there is an email address assigned
        if (!empty($info->email)) {
            // Collect Mailchimp customer info
            if (($mailchimpInfo = $this->mailchimp_lib->getMemberInfo($this->_list_id, $info->email)) !== false) {
                $data['mailchimp_info'] = $mailchimpInfo;

                // Collect customer Mailchimp emails activities (stats)
                if (($activities = $this->mailchimp_lib->getMemberActivity($this->_list_id, $info->email)) !== false) {
                    if (array_key_exists('activity', $activities)) {
                        $open = 0;
                        $unopen = 0;
                        $click = 0;
                        $total = 0;
                        $lastopen = '';

                        foreach ($activities['activity'] as $activity) {
                            if ($activity['action'] == 'sent') {
                                ++$unopen;
                            } elseif ($activity['action'] == 'open') {
                                if (empty($lastopen)) {
                                    $lastopen = substr($activity['timestamp'], 0, 10);
                                }
                                ++$open;
                            } elseif ($activity['action'] == 'click') {
                                if (empty($lastopen)) {
                                    $lastopen = substr($activity['timestamp'], 0, 10);
                                }
                                ++$click;
                            }

                            ++$total;
                        }

                        $data['mailchimp_activity']['total'] = $total;
                        $data['mailchimp_activity']['open'] = $open;
                        $data['mailchimp_activity']['unopen'] = $unopen;
                        $data['mailchimp_activity']['click'] = $click;
                        $data['mailchimp_activity']['lastopen'] = $lastopen;
                    }
                }
            }
        }

        return view("customers/form", $data);
    }

    /**
     * Inserts/updates a customer
     * @return ResponseInterface
     */
    public function postSave(int $customerId = NEW_ENTRY): ResponseInterface
    {
        $firstName = $this->request->getPost('first_name');
        $lastName = $this->request->getPost('last_name');
        $email = strtolower($this->request->getPost('email', FILTER_SANITIZE_EMAIL));

        // Format first and last name properly
        $firstName = $this->nameize($firstName);
        $lastName = $this->nameize($lastName);

        $personData = [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'gender'       => $this->request->getPost('gender', FILTER_SANITIZE_NUMBER_INT),
            'email'        => $email,
            'phone_number' => $this->request->getPost('phone_number'),
            'address_1'    => $this->request->getPost('address_1'),
            'address_2'    => $this->request->getPost('address_2'),
            'city'         => $this->request->getPost('city'),
            'state'        => $this->request->getPost('state'),
            'zip'          => $this->request->getPost('zip'),
            'country'      => $this->request->getPost('country'),
            'comments'     => $this->request->getPost('comments')
        ];

        $customerData = [
            'consent'           => $this->request->getPost('consent') != null,
            'account_number'    => $this->request->getPost('account_number') == '' ? null : $this->request->getPost('account_number'),
            'tax_id'            => $this->request->getPost('tax_id'),
            'company_name'      => $this->request->getPost('company_name') == '' ? null : $this->request->getPost('company_name'),
            'discount'          => $this->request->getPost('discount') == '' ? 0.00 : parse_decimals($this->request->getPost('discount')),
            'discount_type'     => $this->request->getPost('discount_type') == null ? PERCENT : $this->request->getPost('discount_type', FILTER_SANITIZE_NUMBER_INT),
            'package_id'        => $this->request->getPost('package_id') == '' ? null : $this->request->getPost('package_id'),
            'taxable'           => $this->request->getPost('taxable') != null,
            'employee_id'       => $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT),
            'sales_tax_code_id' => $this->request->getPost('sales_tax_code_id') == '' ? null : $this->request->getPost('sales_tax_code_id', FILTER_SANITIZE_NUMBER_INT)
        ];

        $isNew = $customerId == NEW_ENTRY || ! $this->customer->exists((int) $customerId);

        if ($isNew) {
            $customerData['date'] = date('Y-m-d H:i:s');
        }

        $before = $isNew ? null : $this->customer->get_info((int) $customerId);
        $master = [];

        try {
            PartyLengths::check(
                ['company_name' => (string) $this->request->getPost('company_name'), 'city' => (string) $personData['city'], 'state' => (string) $personData['state'], 'zip' => (string) $personData['zip']],
                $before,
                ['company_name' => lang('Customers.company_name'), 'city' => lang('Common.city'), 'state' => lang('Common.state'), 'zip' => lang('Common.zip')],
            );
        } catch (AccountingException $failure) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($failure), 'id' => NEW_ENTRY]);
        }

        if ($this->request->getPost(CustomerFields::MARKER) === '1') {
            try {
                $master = (new CustomerFields())->read($this->request->getPost(), $before, $this->viewerId(), $email);
            } catch (AccountingException $failure) {
                return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($failure), 'id' => NEW_ENTRY]);
            }

            $customerData += $master;
        }

        if ($customerData['account_number'] !== null && $this->customer->refusesAccountNumber($customerData['account_number'], (string) $customerId)) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Customers.account_number_duplicate'), 'id' => NEW_ENTRY]);
        }

        $pricingRefusal = $this->refusalOfPricing((int) $customerId);

        if ($pricingRefusal !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $pricingRefusal, 'id' => NEW_ENTRY]);
        }

        $eInvoice = $this->eInvoiceGiven();

        if ($eInvoice['refusal'] !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $eInvoice['refusal'], 'id' => NEW_ENTRY]);
        }

        if ($this->customer->save_customer($personData, $customerData, $customerId)) {
            if ($master !== []) {
                (new CustomerFields())->audit((int) ($customerData['person_id'] ?? $customerId), $before, $master, $this->viewerId());
            }

            if ($eInvoice['party'] !== null) {
                (new CustomerParties())->save((int) ($customerData['person_id'] ?? $customerId), $eInvoice['party'], $this->viewerId());
            }

            $notSaved    = $this->saveCustomerPricing((int) ($customerData['person_id'] ?? $customerId));
            $pricingNote = $notSaved === null ? '' : ' ' . lang('Customers.pricing_not_saved', [$notSaved]);

            // Save customer to Mailchimp selected list    // TODO: addOrUpdateMember should be refactored. Potentially pass an array or object instead of 6 parameters.
            $mailchimpStatus = $this->request->getPost('mailchimp_status');
            $this->mailchimp_lib->addOrUpdateMember(
                $this->_list_id,
                $email,
                $firstName,
                $lastName,
                $mailchimpStatus == null ? "" : $mailchimpStatus,
                ['vip' => $this->request->getPost('mailchimp_vip') != null]
            );

            // New customer
            if ($customerId == NEW_ENTRY) {
                $notEntered = $this->enterOpening((int) $customerData['person_id']);

                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Customers.successful_adding') . ' ' . $firstName . ' ' . $lastName . $pricingNote . ($notEntered === null ? '' : ' ' . lang('Customers.opening_not_entered', [$notEntered])),
                    'id'      => $customerData['person_id']
                ]);
            } else { // Existing customer
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Customers.successful_updating') . ' ' . $firstName . ' ' . $lastName . $pricingNote,
                    'id'      => $customerId
                ]);
            }
        } else { // Failure
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Customers.error_adding_updating') . ' ' . $firstName . ' ' . $lastName,
                'id'      => NEW_ENTRY
            ]);
        }
    }

    /**
     * What the form of a customer shows for the electronic invoice, when that layer is on: the eleven
     * identification types and what the customer holds, and whether the tax number is a valid one.
     *
     * @return array<string, mixed>
     */
    private function eInvoiceFormData(object $info): array
    {
        if (! (new EInvoiceSettings())->isEnabled()) {
            return ['eInvoice' => null];
        }

        $personId = (int) ($info->person_id ?? 0);
        $vat      = trim((string) ($info->tax_id ?? ''));

        return ['eInvoice' => [
            'types'       => array_map(static fn (PartyIdentificationType $type): array => ['code' => $type->value, 'label' => lang($type->languageKey())], PartyIdentificationType::cases()),
            'party'       => $personId > 0 ? (new CustomerParties())->find($personId) : array_merge(array_fill_keys(CustomerParties::FIELDS, ''), ['country_code' => 'SA']),
            'vat_invalid' => $vat !== '' && ! EInvoiceRules::vatNumber($vat),
        ]];
    }

    /**
     * The identification and the national address the form gave, checked before the customer is saved, or
     * the reason they are refused. Null stands for a form that did not carry them.
     *
     * @return array{party: ?array<string, string>, refusal: ?string}
     */
    private function eInvoiceGiven(): array
    {
        if ($this->request->getPost('einvoice_party') !== '1' || ! (new EInvoiceSettings())->isEnabled()) {
            return ['party' => null, 'refusal' => null];
        }

        $input = [];

        foreach (CustomerParties::FIELDS as $field) {
            $input[$field] = (string) $this->request->getPost('einvoice_' . $field);
        }

        try {
            return ['party' => (new CustomerParties())->clean($input), 'refusal' => null];
        } catch (EInvoiceException $failure) {
            return ['party' => null, 'refusal' => $failure->describe()];
        }
    }

    /**
     * What the form of a customer shows of the fields that the books never read: the lists to choose from
     * and the values the customer holds now (an inactive classification the customer holds stays on offer).
     *
     * @return array<string, mixed>
     */
    private function masterDataFormData(object $info): array
    {
        $held            = (int) ($info->classification_id ?? 0);
        $classifications = [0 => lang('Customers.classification_none')];

        foreach ((new NamedLookup(LookupKind::CustomerClassification))->all() as $row) {
            if ((int) $row->status === 1 || (int) $row->classification_id === $held) {
                $classifications[(int) $row->classification_id] = $row->name . ((int) $row->status === 1 ? '' : ' (' . lang('Lookups.inactive') . ')');
            }
        }

        $employees = [0 => lang('Customers.assigned_employee_none')] + (new CustomerFields())->assignableEmployees($this->viewerId());
        $assigned  = (int) ($info->assigned_employee_id ?? 0);

        if ($assigned > 0 && ! isset($employees[$assigned])) {
            $employees[$assigned] = lang('Customers.assigned_other_branch');
        }

        return [
            'classificationOptions' => $classifications,
            'statusOptions'         => ['' => lang('Customers.status_none')] + CustomerStatus::options(),
            'typeOptions'           => ['' => lang('Customers.type_none')] + CustomerType::options(),
            'billingOptions'        => BillingMethod::options(),
            'employeeOptions'       => $employees,
            'masterData'            => [
                'classification_id'    => $held,
                'manual_status'        => (string) ($info->manual_status ?? ''),
                'assigned_employee_id' => $assigned,
                'area'                 => (string) ($info->area ?? ''),
                'country_code'         => (string) ($info->country_code ?? ''),
                'customer_type'        => (string) ($info->customer_type ?? ''),
                'is_government_entity' => (int) ($info->is_government_entity ?? 0),
                'billing_method'       => (string) ($info->billing_method ?? '') === '' ? BillingMethod::Print->value : (string) $info->billing_method,
            ],
        ];
    }

    /**
     * What the form of a customer shows of the price lists and of the credit limit: the lists to
     * choose from when the price lists are on, and the limit and the days when those controls are on.
     *
     * @return array<string, mixed>
     */
    private function pricingFormData(object $info): array
    {
        $settings   = new SalesControlSettings();
        $accounting = (new AccountingSettings())->isEnabled();
        $options    = null;
        $assigned   = isset($info->price_list_id) ? (int) $info->price_list_id : 0;

        if ($settings->priceListsEnabled()) {
            $options = [0 => lang('Customers.price_list_none')];

            foreach ($this->priceList->allLists() as $list) {
                if ((int) $list->status === 1 || (int) $list->price_list_id === $assigned) {
                    $options[(int) $list->price_list_id] = esc($list->name) . ((int) $list->status === 1 ? '' : ' (' . esc(lang('Price_lists.inactive')) . ')');
                }
            }
        }

        $terms = isset($info->person_id) && (int) $info->person_id > 0 ? (new CreditLimit())->termsOf((int) $info->person_id) : ['limit' => '0.0000', 'days' => 0];

        return [
            'priceListOptions'   => $options,
            'priceListSelected'  => $assigned,
            'canAssignPriceList' => $this->employee->has_grant('price_lists', (int) $this->employee->get_logged_in_employee_info()->person_id),
            'creditLimitShown'   => $accounting && $settings->creditLimitEnabled(),
            'creditPeriodShown'  => $accounting && $settings->creditPeriodEnabled(),
            'creditEditable'     => $this->creditTermsEditable(),
            'creditLimit'        => $terms['limit'],
            'creditDays'         => $terms['days'],
        ];
    }

    /**
     * The credit limit and the credit days are set by whoever may manage what customers owe, and only
     * from every branch, because they hold for the whole company.
     */
    private function creditTermsEditable(): bool
    {
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;

        return (new AccountingSettings())->isEnabled()
            && $this->employee->has_grant(AccountingGuard::RECEIVABLES_MANAGE, $employeeId)
            && (new BranchGuard(null, $this->employee))->isUnrestricted($employeeId);
    }

    /**
     * The price list and the credit terms the form gave, for whoever may set them. What the form
     * leaves out, or what the employee may not set, is null and stays as it is.
     *
     * @return array{list: ?int, limit: ?string, days: ?string}
     */
    private function pricingGiven(): array
    {
        $settings   = new SalesControlSettings();
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $given      = ['list' => null, 'limit' => null, 'days' => null];

        if ($settings->priceListsEnabled() && $this->request->getPost('price_list_id') !== null && $this->employee->has_grant('price_lists', $employeeId)) {
            $given['list'] = (int) $this->request->getPost('price_list_id');
        }

        if ($this->creditTermsEditable()) {
            if ($settings->creditLimitEnabled() && $this->request->getPost('credit_limit') !== null) {
                $given['limit'] = $this->amountOf((string) $this->request->getPost('credit_limit'));
            }

            if ($settings->creditPeriodEnabled() && $this->request->getPost('credit_period_days') !== null) {
                $given['days'] = trim((string) $this->request->getPost('credit_period_days'));
            }
        }

        return $given;
    }

    /**
     * Why the price list or the credit terms of the form cannot be saved, asked before the customer
     * is saved so that a customer is never saved next to a refusal.
     */
    private function refusalOfPricing(int $customerId): ?string
    {
        $given = $this->pricingGiven();

        try {
            $this->priceList->checkAssignable($customerId, $given['list']);
            (new CreditLimit())->checkTerms($given['limit'], $given['days']);
        } catch (AccountingException $failure) {
            return AccountingMessage::of($failure);
        }

        return null;
    }

    /**
     * Saves the price list and the credit terms the form gave. What the books or the lists refuse
     * after all, when they changed since the form was checked, is returned as the reason.
     */
    private function saveCustomerPricing(int $customerId): ?string
    {
        $given      = $this->pricingGiven();
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;

        try {
            if ($given['list'] !== null) {
                $this->priceList->assignTo($customerId, $given['list'], $employeeId);
            }

            if ($given['limit'] !== null || $given['days'] !== null) {
                (new CreditLimit())->saveTerms($customerId, $given['limit'], $given['days'], $employeeId);
            }
        } catch (AccountingException $failure) {
            return AccountingMessage::of($failure);
        }

        return null;
    }

    /**
     * A number typed in the way the shop writes numbers, as the plain number the books read, or the
     * text itself when it is not a number so that it is refused.
     */
    private function amountOf(string $typed): string
    {
        $typed = trim($typed);

        if ($typed === '') {
            return '0';
        }

        $parsed = parse_decimals($typed);

        return $parsed === false ? $typed : number_format((float) $parsed, 4, '.', '');
    }

    /**
     * The statement of a customer, where an opening balance is changed or cancelled, is closed to an
     * employee limited to some branches, so the form does not offer one an opening balance either.
     */
    private function openingOffered(): bool
    {
        $settings   = new AccountingSettings();
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;

        return $settings->isEnabled()
            && $settings->customerOpeningEnabled()
            && (new BranchGuard(null, $this->employee))->isUnrestricted($employeeId)
            && $this->employee->has_grant(AccountingGuard::OPENING_MANAGE, $employeeId);
    }

    /**
     * Enters the opening balance typed on the form of a new customer, which the customer does not
     * wait for: the customer is saved either way. What the books refuse is returned as the reason.
     */
    private function enterOpening(int $customerId): ?string
    {
        $amount = trim((string) $this->request->getPost('opening_amount'));

        if ($amount === '' || ! $this->openingOffered()) {
            return null;
        }

        try {
            (new CustomerOpening(null, null, new BranchGuard(null, $this->employee)))->create(
                $customerId,
                (string) $this->request->getPost('opening_side'),
                $amount,
                trim((string) $this->request->getPost('opening_date')),
                '',
                (int) $this->employee->get_logged_in_employee_info()->person_id,
            );
        } catch (AccountingException|BranchException|PostingException $failure) {
            return AccountingMessage::of($failure);
        }

        return null;
    }

    /**
     * Verifies if an email address already exists. Used in app/Views/customers/form.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCheckEmail(): ResponseInterface
    {
        $email = strtolower($this->request->getPost('email', FILTER_SANITIZE_EMAIL));
        $person_id = $this->request->getPost('person_id', FILTER_SANITIZE_NUMBER_INT);

        $exists = $this->customer->check_email_exists($email, $person_id);

        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * Verifies if an account number already exists. Used in app/Views/customers/form.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCheckAccountNumber(): ResponseInterface
    {
        $refused = $this->customer->refusesAccountNumber((string) $this->request->getPost('account_number'), (string) $this->request->getPost('person_id', FILTER_SANITIZE_NUMBER_INT));

        return $this->response->setJSON(!$refused ? 'true' : 'false');
    }

    /**
     * This deletes customers from the customers table
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $customers_to_delete = $this->request->getPost('ids');
        $customers_info = $this->customer->get_multiple_info($customers_to_delete);

        $count = 0;

        foreach ($customers_info->getResult() as $info) {
            if ($this->customer->delete($info->person_id)) {
                // remove customer from Mailchimp selected list
                $this->mailchimp_lib->removeMember($this->_list_id, $info->email);

                $count++;
            }
        }

        if ($count == count($customers_to_delete)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Customers.successful_deleted') . ' ' . $count . ' ' . lang('Customers.one_or_multiple')
            ]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Customers.cannot_be_deleted')]);
        }
    }

    /**
     * The columns an import row adds to the eighteen the template always had: the classification (by name),
     * the manual status (by code), the assigned employee (by user name), the area, the country code, the type,
     * the government flag and the billing method. A row that stops at the eighteenth column, or leaves them all
     * empty, imports as it always did; a value that is not allowed refuses the row.
     *
     * @param list<string|null> $data
     *
     * @return array<string, int|string|null>
     *
     * @throws AccountingException
     */
    private function masterDataOfImportRow(array $data, string $email): array
    {
        $cell = static fn (int $position): string => trim((string) ($data[$position] ?? ''));

        if (implode('', array_map($cell, range(self::IMPORT_BASE_COLUMNS, self::IMPORT_BASE_COLUMNS + 7))) === '') {
            return [];
        }

        $classificationId = '';

        if ($cell(18) !== '') {
            $found = array_search(mb_strtolower($cell(18)), array_map('mb_strtolower', (new NamedLookup(LookupKind::CustomerClassification))->options()), true);

            if ($found === false) {
                throw new AccountingException(AccountingError::CustomerClassificationInvalid, 'The classification does not exist or is inactive.');
            }

            $classificationId = (string) $found;
        }

        $employeeId = '';

        if ($cell(20) !== '') {
            $found = Database::connect()->table('employees')->select('person_id')->where(['username' => $cell(20), 'deleted' => 0])->get()->getRow();

            if ($found === null) {
                throw new AccountingException(AccountingError::CustomerEmployeeInvalid, 'The employee does not exist.');
            }

            $employeeId = (string) $found->person_id;
        }

        return (new CustomerFields())->read([
            'classification_id'    => $classificationId,
            'manual_status'        => $cell(19),
            'assigned_employee_id' => $employeeId,
            'area'                 => $cell(21),
            'country_code'         => $cell(22),
            'customer_type'        => $cell(23),
            'is_government_entity' => CustomerFields::flag($cell(24)),
            'billing_method'       => $cell(25),
        ], null, $this->viewerId(), $email);
    }

    /**
     * Customers import from csv spreadsheet
     *
     * @return DownloadResponse The template for Customer CSV imports is returned and download forced.
     * @noinspection PhpUnused
     */
    public function getCsv(): DownloadResponse
    {
        $name = 'importCustomers.csv';
        $data = file_get_contents(WRITEPATH . "uploads/$name");
        return $this->response->download($name, $data);
    }

    /**
     * Displays the customer CSV import modal. Used in app/Views/people/manage.php
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getCsvImport(): string
    {
        return view('customers/form_csv_import');
    }

    /**
     * Imports a CSV file containing customers. Used in app/Views/customers/form_csv_import.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postImportCsvFile(): ResponseInterface
    {
        if ($_FILES['file_path']['error'] != UPLOAD_ERR_OK) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Customers.csv_import_failed')]);
        } else {
            if (($handle = fopen($_FILES['file_path']['tmp_name'], 'r')) !== false) {
                // Skip the first row as it's the table description
                fgetcsv($handle);
                $i = 1;

                $failCodes = [];

                while (($data = fgetcsv($handle)) !== false) {
                    $consent = $data[3] == '' ? 0 : 1;

                    if (sizeof($data) >= 16 && $consent) {
                        $email = filter_var(strtolower($data[4]), FILTER_SANITIZE_EMAIL);
                        
                        // Empty email is allowed, but if provided it must be valid
                        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $failCodes[] = 'Row ' . $i . ': Invalid email format';
                            $i++;
                            continue;
                        }
                        
                        $personData = [
                            'first_name'   => $data[0],
                            'last_name'    => $data[1],
                            'gender'       => $data[2],
                            'email'        => $email,
                            'phone_number' => $data[5],
                            'address_1'    => $data[6],
                            'address_2'    => $data[7],
                            'city'         => $data[8],
                            'state'        => $data[9],
                            'zip'          => $data[10],
                            'country'      => $data[11],
                            'comments'     => $data[12]
                        ];

                        $customerData = [
                            'consent'       => $consent,
                            'company_name'  => $data[13],
                            'discount'      => $data[15],
                            'discount_type' => $data[16],
                            'taxable'       => $data[17] == '' ? 0 : 1,
                            'date'          => date('Y-m-d H:i:s'),
                            'employee_id'   => $this->employee->get_logged_in_employee_info()->person_id
                        ];
                        $accountNumber = $data[14];
                        $master        = [];

                        // Don't duplicate people with same email
                        $invalidated = $this->customer->check_email_exists($email);

                        if ($accountNumber != '') {
                            $customerData['account_number'] = $accountNumber;
                            $invalidated &= $this->customer->check_account_number_exists($accountNumber);
                            $invalidated |= $this->customer->refusesAccountNumber($accountNumber);
                        }

                        try {
                            $master        = $this->masterDataOfImportRow($data, $email);
                            $customerData += $master;
                        } catch (AccountingException $failure) {
                            $invalidated = true;
                            log_message('error', "Row $i was not imported: " . $failure->getMessage());
                        }
                    } else {
                        $invalidated = true;
                    }

                    if ($invalidated) {
                        $failCodes[] = $i;
                        log_message('error', "Row $i was not imported: Either email or account number already exist or data was invalid.");
                    } elseif ($this->customer->save_customer($personData, $customerData)) {
                        if ($master !== []) {
                            (new CustomerFields())->audit((int) $customerData['person_id'], null, $master, $this->viewerId());
                        }

                        // Save customer to Mailchimp selected list
                        $this->mailchimp_lib->addOrUpdateMember($this->_list_id, $personData['email'], $personData['first_name'], '', $personData['last_name']);
                    } else {
                        $failCodes[] = $i;
                    }

                    ++$i;
                }

                if (count($failCodes) > 0) {
                    $message = lang('Customers.csv_import_partially_failed', [count($failCodes), implode(', ', $failCodes)]);

                    return $this->response->setJSON(['success' => false, 'message' => $message]);
                } else {
                    return $this->response->setJSON(['success' => true, 'message' => lang('Customers.csv_import_success')]);
                }
            } else {
                return $this->response->setJSON(['success' => false, 'message' => lang('Customers.csv_import_nodata_wrongformat')]);
            }
        }
    }
}
