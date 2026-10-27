<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var array $packages
 * @var int $selected_package
 * @var bool $use_destination_based_tax
 * @var string $sales_tax_code_label
 * @var string $employee
 * @var string|null $automatic_account_number
 * @var array $config
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>

<?= form_open("$controller_name/save/$person_info->person_id", ['id' => 'customer_form', 'class' => 'app-form']) ?>

    <ul class="nav nav-tabs nav-justified" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link active" data-bs-toggle="tab" role="tab" aria-controls="customer_basic_info" href="#customer_basic_info"><?= lang('Customers.basic_information') ?></a>
        </li>
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
                        <div class="field">
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
                            <?php if (! empty($automatic_account_number)) { ?>
                                <div class="form-text"><?= lang('Customers.account_number_automatic', [esc($automatic_account_number)]) ?></div>
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
