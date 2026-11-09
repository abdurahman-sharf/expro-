<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var array $packages
 * @var int $selected_package
 * @var bool $use_destination_based_tax
 * @var string $sales_tax_code_label
 * @var string $employee
 * @var string|null $automaticAccountNumber
 * @var array $config
 * @var bool $opening_offered
 * @var string $opening_today
 * @var array<int, string>|null $priceListOptions
 * @var int $priceListSelected
 * @var bool $canAssignPriceList
 * @var bool $creditLimitShown
 * @var bool $creditPeriodShown
 * @var bool $creditEditable
 * @var string $creditLimit
 * @var int $creditDays
 * @var array<int, string> $classificationOptions
 * @var array<string, string> $statusOptions
 * @var array<string, string> $typeOptions
 * @var array<string, string> $billingOptions
 * @var array<int, string> $employeeOptions
 * @var array<string, mixed> $masterData
 * @var list<object> $contacts
 * @var array{enabled: bool, account: object|null, customerId: int}|null $portal
 */

$masterData        ??= null;
$priceListOptions  ??= null;
$creditLimitShown  ??= false;
$creditPeriodShown ??= false;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>

<?= form_open("$controller_name/save/$person_info->person_id", ['id' => 'customer_form', 'class' => 'app-form']) ?>

    <ul class="nav nav-tabs nav-justified" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link active" data-bs-toggle="tab" role="tab" aria-controls="customer_basic_info" href="#customer_basic_info"><?= lang('Customers.basic_information') ?></a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="customer_contacts" href="#customer_contacts"><?= lang('Customers.contacts') ?></a>
        </li>
        <?php if (!empty($portal)) { ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="customer_portal" href="#customer_portal"><?= lang('Portal.manage_tab') ?></a>
            </li>
        <?php } ?>
        <?php if (!empty($stats)) { ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="customer_stats_info" href="#customer_stats_info"><?= lang('Customers.stats_info') ?></a>
            </li>
        <?php } ?>
        <?php if (!empty($mailchimp_info) && !empty($mailchimp_activity)) { ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="customer_mailchimp_info" href="#customer_mailchimp_info"><?= lang('Customers.mailchimp_info') ?></a>
            </li>
        <?php } ?>
    </ul>

    <ul id="error_message_box" class="error_message_box"></ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="customer_basic_info" role="tabpanel">
            <fieldset>
                <div class="field field-check">
                    <?= form_label(lang('Customers.consent'), 'consent', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <?= form_checkbox('consent', 1, $person_info->consent == '' ? !$config['enforce_privacy'] : (bool)$person_info->consent, 'id="consent"') ?>
                    </div>
                </div>

                <?= view('people/form_basic_info') ?>

                <div class="form-section">
                    <h3 class="form-section-title"><?= lang('Common.section_account') ?></h3>
                    <div class="form-grid">
                        <div class="field" id="customer_company_row">
                            <?= form_label(lang('Customers.company_name'), 'customer_company_name', ['class' => 'form-label']) ?>
                            <?= form_input([
                                'name'  => 'company_name',
                                'id'    => 'customer_company_name',
                                'class' => 'form-control form-control-sm',
                                'value' => $person_info->company_name
                            ]) ?>
                        </div>

                        <div class="field">
                            <?= form_label(lang('Customers.account_number'), 'account_number', ['class' => 'form-label']) ?>
                            <?= form_input([
                                'name'  => 'account_number',
                                'id'    => 'account_number',
                                'class' => 'form-control form-control-sm',
                                'value' => $person_info->account_number
                            ]) ?>
                            <?php if (! empty($automaticAccountNumber)) { ?>
                                <div class="form-text"><?= lang('Customers.account_number_automatic', [esc($automaticAccountNumber)]) ?></div>
                            <?php } ?>
                        </div>

                        <div class="field">
                            <?= form_label(lang('Customers.tax_id'), 'tax_id', ['class' => 'form-label']) ?>
                            <?= form_input([
                                'name'  => 'tax_id',
                                'id'    => 'tax_id',
                                'class' => 'form-control form-control-sm',
                                'value' => $person_info->tax_id
                            ]) ?>
                        </div>

                        <?php if ($use_destination_based_tax) { ?>
                            <div class="field">
                                <?= form_label(lang('Customers.tax_code'), 'sales_tax_code_name', ['class' => 'form-label']) ?>
                                <?= form_input([
                                    'name'  => 'sales_tax_code_name',
                                    'id'    => 'sales_tax_code_name',
                                    'class' => 'form-control form-control-sm',
                                    'value' => $sales_tax_code_label
                                ]) ?>
                                <?= form_hidden('sales_tax_code_id', $person_info->sales_tax_code_id) ?>
                            </div>
                        <?php } ?>

                        <?php if ($config['customer_reward_enable']): ?>
                            <div class="field">
                                <?= form_label(lang('Customers.rewards_package'), 'package_id', ['class' => 'form-label']) ?>
                                <?= form_dropdown('package_id', $packages, $selected_package, 'id="package_id" class="form-select form-select-sm"') ?>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.available_points'), 'available_points', ['class' => 'form-label']) ?>
                                <?= form_input([
                                    'name'     => 'available_points',
                                    'id'       => 'available_points',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => $person_info->points,
                                    'disabled' => ''
                                ]) ?>
                            </div>
                        <?php endif; ?>

                        <div class="field">
                            <span id="discount_type_label" class="form-label"><?= lang('Customers.discount_type') ?></span>
                            <div class="field-control" role="radiogroup" aria-labelledby="discount_type_label">
                                <label class="choice">
                                    <?= form_radio([
                                        'name'    => 'discount_type',
                                        'type'    => 'radio',
                                        'id'      => 'discount_type_percent',
                                        'value'   => 0,
                                        'checked' => $person_info->discount_type == PERCENT
                                    ]) ?> <?= lang('Customers.discount_percent') ?>
                                </label>
                                <label class="choice">
                                    <?= form_radio([
                                        'name'    => 'discount_type',
                                        'type'    => 'radio',
                                        'id'      => 'discount_type_fixed',
                                        'value'   => 1,
                                        'checked' => $person_info->discount_type == FIXED
                                    ]) ?> <?= lang('Customers.discount_fixed') ?>
                                </label>
                            </div>
                        </div>

                        <div class="field">
                            <?= form_label(lang('Customers.discount'), 'discount', ['class' => 'form-label']) ?>
                            <?= form_input([
                                'name'    => 'discount',
                                'id'      => 'discount',
                                'class'   => 'form-control form-control-sm',
                                'onClick' => 'this.select();',
                                'value'   => $person_info->discount_type === FIXED ? to_currency_no_money($person_info->discount) : to_decimals($person_info->discount)
                            ]) ?>
                        </div>

                        <div class="field field-check">
                            <?= form_label(lang('Customers.taxable'), 'taxable', ['class' => 'form-label']) ?>
                            <div class="field-control">
                                <?= form_checkbox('taxable', 1, $person_info->taxable == 1, 'id="taxable"') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($priceListOptions !== null || $creditLimitShown || $creditPeriodShown): ?>
                    <div class="form-section" id="customer_pricing_section">
                        <h3 class="form-section-title"><?= lang('Customers.pricing_and_credit') ?></h3>
                        <div class="form-grid">
                            <?php if ($priceListOptions !== null): ?>
                                <div class="field">
                                    <?= form_label(lang('Customers.price_list'), 'price_list_id', ['class' => 'form-label']) ?>
                                    <div class="field-control">
                                        <?= form_dropdown('price_list_id', $priceListOptions, (string) $priceListSelected, ['class' => 'form-select form-select-sm', 'id' => 'price_list_id'] + ($canAssignPriceList ? [] : ['disabled' => 'disabled'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($creditLimitShown): ?>
                                <div class="field">
                                    <?= form_label(lang('Customers.credit_limit'), 'credit_limit', ['class' => 'form-label']) ?>
                                    <div class="field-control">
                                        <?= form_input(['name' => 'credit_limit', 'id' => 'credit_limit', 'class' => 'form-control form-control-sm', 'inputmode' => 'decimal', 'dir' => 'ltr', 'value' => to_currency_no_money($creditLimit)] + ($creditEditable ? [] : ['disabled' => 'disabled'])) ?>
                                        <p class="form-text mb-0"><?= lang($creditEditable ? 'Customers.credit_limit_help' : 'Customers.credit_terms_locked') ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($creditPeriodShown): ?>
                                <div class="field">
                                    <?= form_label(lang('Customers.credit_period_days'), 'credit_period_days', ['class' => 'form-label']) ?>
                                    <div class="field-control">
                                        <?= form_input(['name' => 'credit_period_days', 'id' => 'credit_period_days', 'class' => 'form-control form-control-sm', 'inputmode' => 'numeric', 'dir' => 'ltr', 'value' => (string) $creditDays] + ($creditEditable ? [] : ['disabled' => 'disabled'])) ?>
                                        <p class="form-text mb-0"><?= lang('Customers.credit_period_help') ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (! empty($opening_offered)): ?>
                    <div class="form-section" id="customer_opening_section">
                        <h3 class="form-section-title"><?= lang('Customers.opening_balance') ?></h3>
                        <p class="form-text"><?= lang('Customers.opening_balance_note') ?></p>
                        <div class="form-grid">
                            <div class="field">
                                <?= form_label(lang('Customers.opening_side'), 'opening_side', ['class' => 'form-label']) ?>
                                <?= form_dropdown('opening_side', ['owes_us' => lang('Customers.opening_owes_us'), 'we_owe' => lang('Customers.opening_we_owe')], 'owes_us', 'id="opening_side" class="form-select form-select-sm"') ?>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.opening_amount'), 'opening_amount', ['class' => 'form-label']) ?>
                                <input type="text" name="opening_amount" id="opening_amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off">
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.opening_date'), 'opening_date', ['class' => 'form-label']) ?>
                                <input type="date" name="opening_date" id="opening_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($opening_today, 'attr') ?>" value="<?= esc($opening_today, 'attr') ?>">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($masterData !== null): ?>
                    <div class="form-section" id="customer_master_data_section">
                        <h3 class="form-section-title"><?= lang('Customers.master_data') ?></h3>
                        <?= form_hidden('master_data', '1') ?>
                        <div class="form-grid">
                            <div class="field">
                                <span id="customer_type_label" class="form-label"><?= lang('Customers.type') ?></span>
                                <div class="field-control" role="radiogroup" aria-labelledby="customer_type_label">
                                    <?php foreach ($typeOptions as $typeValue => $typeLabel): ?>
                                        <?php if ($typeValue === '') {
                                            continue;
                                        } ?>
                                        <label class="choice">
                                            <?= form_radio(['name' => 'customer_type', 'id' => 'customer_type_' . $typeValue, 'value' => $typeValue, 'checked' => $masterData['customer_type'] === $typeValue]) ?> <?= esc($typeLabel) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.classification'), 'classification_id', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_dropdown('classification_id', array_map('esc', $classificationOptions), (string) $masterData['classification_id'], 'id="classification_id" class="form-select form-select-sm"') ?>
                                    <p class="form-text mb-0"><?= anchor('customer_classifications', lang('Customers.classifications_manage'), ['target' => '_blank', 'rel' => 'noopener']) ?></p>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.manual_status'), 'manual_status', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_dropdown('manual_status', array_map('esc', $statusOptions), $masterData['manual_status'], 'id="manual_status" class="form-select form-select-sm"') ?>
                                    <p class="form-text mb-0"><?= lang('Customers.manual_status_help') ?></p>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.assigned_employee'), 'assigned_employee_id', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_dropdown('assigned_employee_id', array_map('esc', $employeeOptions), (string) $masterData['assigned_employee_id'], 'id="assigned_employee_id" class="form-select form-select-sm"') ?>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.area'), 'area', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_input(['name' => 'area', 'id' => 'area', 'class' => 'form-control form-control-sm', 'maxlength' => '100', 'value' => $masterData['area']]) ?>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.country_code'), 'country_code', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_input(['name' => 'country_code', 'id' => 'country_code', 'class' => 'form-control form-control-sm', 'maxlength' => '2', 'dir' => 'ltr', 'value' => $masterData['country_code']]) ?>
                                    <p class="form-text mb-0"><?= lang('Customers.country_code_help') ?></p>
                                </div>
                            </div>

                            <div class="field field-check">
                                <?= form_label(lang('Customers.government_entity'), 'is_government_entity', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_checkbox('is_government_entity', 1, $masterData['is_government_entity'] === 1, 'id="is_government_entity"') ?>
                                </div>
                            </div>

                            <div class="field">
                                <?= form_label(lang('Customers.billing_method'), 'billing_method', ['class' => 'form-label']) ?>
                                <div class="field-control">
                                    <?= form_dropdown('billing_method', array_map('esc', $billingOptions), $masterData['billing_method'], 'id="billing_method" class="form-select form-select-sm"') ?>
                                    <p class="form-text mb-0"><?= lang('Customers.billing_method_help') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?= view('people/form_comments') ?>

                <div class="form-grid">
                    <div class="field">
                        <?= form_label(lang('Customers.date'), 'datetime', ['class' => 'form-label']) ?>
                        <div class="input-group">
                            <span class="input-group-text"><span class="bi bi-calendar3" aria-hidden="true"></span></span>
                            <?= form_input([
                                'name'     => 'date',
                                'id'       => 'datetime',
                                'class'    => 'form-control form-control-sm',
                                'value'    => to_datetime(strtotime($person_info->date)),
                                'readonly' => 'true'
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.employee'), 'employee', ['class' => 'form-label']) ?>
                        <?= form_input([
                            'name'     => 'employee',
                            'id'       => 'employee',
                            'class'    => 'form-control form-control-sm',
                            'value'    => $employee,
                            'readonly' => 'true'
                        ]) ?>
                    </div>
                </div>

                <?= form_hidden('employee_id', $person_info->employee_id) ?>
            </fieldset>
        </div>

        <div class="tab-pane fade" id="customer_contacts" role="tabpanel">
            <fieldset>
                <?= view('people/contacts_panel', ['contacts' => $contacts ?? []]) ?>
            </fieldset>
        </div>

        <?php if (!empty($portal)) { ?>
            <div class="tab-pane fade" id="customer_portal" role="tabpanel">
                <?= view('customer_portal/panel', $portal) ?>
            </div>
        <?php } ?>

        <?php if (!empty($stats)) { ?>
            <div class="tab-pane" id="customer_stats_info" role="tabpanel">
                <fieldset class="form-grid">
                    <div class="field">
                        <?= form_label(lang('Customers.total'), 'total', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <?php if (!is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                                <?= form_input([
                                    'name'     => 'total',
                                    'id'       => 'total',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_currency_no_money($stats->total),
                                    'disabled' => ''
                                ]) ?>
                                <?php if (is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.max'), 'max', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <?php if (!is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                                <?= form_input([
                                    'name'     => 'max',
                                    'id'       => 'max',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_currency_no_money($stats->max),
                                    'disabled' => ''
                                ]) ?>
                                <?php if (is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.min'), 'min', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <?php if (!is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                                <?= form_input([
                                    'name'     => 'min',
                                    'id'       => 'min',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_currency_no_money($stats->min),
                                    'disabled' => ''
                                ]) ?>
                                <?php if (is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.average'), 'average', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <?php if (!is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                                <?= form_input([
                                    'name'     => 'average',
                                    'id'       => 'average',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_currency_no_money($stats->average),
                                    'disabled' => ''
                                ]) ?>
                                <?php if (is_right_side_currency_symbol()): ?>
                                    <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.quantity'), 'quantity', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><b><?= '>' ?></b></span>
                                <?= form_input([
                                    'name'     => 'quantity',
                                    'id'       => 'quantity',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_quantity_decimals($stats->quantity),
                                    'disabled' => ''
                                ]) ?>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.avg_discount'), 'avg_discount', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <div class="input-group input-group-sm">
                                <?= form_input([
                                    'name'     => 'avg_discount',
                                    'id'       => 'avg_discount',
                                    'class'    => 'form-control form-control-sm',
                                    'value'    => to_decimals($stats->avg_discount),
                                    'disabled' => ''
                                ]) ?>
                                <span class="input-group-text"><b>%</b></span>
                            </div>
                        </div>
                    </div>
                </fieldset>
            </div>
        <?php } ?>

        <?php if (!empty($mailchimp_info) && !empty($mailchimp_activity)) { ?>
            <div class="tab-pane" id="customer_mailchimp_info" role="tabpanel">
                <fieldset class="form-grid">
                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_status'), 'mailchimp_status', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_dropdown(
                                'mailchimp_status',
                                [
                                    'subscribed'   => 'subscribed',
                                    'unsubscribed' => 'unsubscribed',
                                    'cleaned'      => 'cleaned',
                                    'pending'      => 'pending'
                                ],
                                $mailchimp_info['status'],
                                ['id' => 'mailchimp_status', 'class' => 'form-select form-select-sm']
                            ) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_vip'), 'mailchimp_vip', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_checkbox('mailchimp_vip', 1, $mailchimp_info['vip'] == 1) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_member_rating'), 'mailchimp_member_rating', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_member_rating',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_info['member_rating'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_activity_total'), 'mailchimp_activity_total', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_activity_total',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_activity['total'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_activity_lastopen'), 'mailchimp_activity_lastopen', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_activity_lastopen',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_activity['lastopen'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_activity_open'), 'mailchimp_activity_open', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_activity_open',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_activity['open'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_activity_click'), 'mailchimp_activity_click', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_activity_click',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_activity['click'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_activity_unopen'), 'mailchimp_activity_unopen', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_activity_unopen',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_activity['unopen'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Customers.mailchimp_email_client'), 'mailchimp_email_client', ['class' => 'form-label']) ?>
                        <div class="field-control">
                            <?= form_input([
                                'name'     => 'mailchimp_email_client',
                                'class'    => 'form-control form-control-sm',
                                'value'    => $mailchimp_info['email_client'],
                                'disabled' => ''
                            ]) ?>
                        </div>
                    </div>
                </fieldset>
            </div>
        <?php } ?>
    </div>

<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $("input[name='sales_tax_code_name']").change(function() {
            if (!$("input[name='sales_tax_code_name']").val()) {
                $("input[name='sales_tax_code_id']").val('');
            }
        });

        const fill_value = function(event, ui) {
            event.preventDefault();
            $("input[name='sales_tax_code_id']").val(ui.item.value);
            $("input[name='sales_tax_code_name']").val(ui.item.label);
        };

        $('#sales_tax_code_name').autocomplete({
            source: "<?= esc('taxes/suggestTaxCodes') ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_value,
            focus: fill_value
        });

        const showCompanyName = function() {
            $('#customer_company_row').toggle($("input[name='customer_type']:checked").val() !== 'individual');
        };

        $("input[name='customer_type']").on('change', showCompanyName);
        showCompanyName();

        $('#customer_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= $controller_name ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                first_name: 'required',
                last_name: 'required',
                consent: 'required',
                email: {
                    remote: {
                        url: "<?= "$controller_name/checkEmail" ?>",
                        type: 'POST',
                        data: {
                            'person_id': "<?= $person_info->person_id ?>"
                            // Email is posted by default
                        }
                    }
                },
                account_number: {
                    remote: {
                        url: "<?= "$controller_name/checkAccountNumber" ?>",
                        type: 'POST',
                        data: {
                            'person_id': "<?= $person_info->person_id ?>"
                            // Account_number is posted by default
                        }
                    }
                }
            },

            messages: {
                first_name: "<?= lang('Common.first_name_required') ?>",
                last_name: "<?= lang('Common.last_name_required') ?>",
                consent: "<?= lang('Customers.consent_required') ?>",
                email: "<?= lang('Customers.email_duplicate') ?>",
                account_number: "<?= lang('Customers.account_number_duplicate') ?>"
            }
        }, form_support.error));
    });
</script>
