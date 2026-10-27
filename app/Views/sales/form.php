<?php
/**
 * @var array $sale_info
 * @var bool $balance_due
 * @var array $new_payment_options
 * @var string $payment_type_new
 * @var float $payment_amount_new
 * @var array $payments
 * @var array $payment_amounts
 * @var array $payment_options
 * @var string $selected_customer_name
 * @var int $selected_customer_id
 * @var string $selected_employee_name
 * @var int $selected_employee_id
 * @var string $controller_name
 * @var array $config
 * @var list<object> $journals
 * @var bool $accounting_on
 * @var bool $issued_lock
 * @var App\Libraries\Accounting\InvoiceFigures|null $receivable
 * @var string|null $credit
 * @var list<object>|null $applications
 * @var string|null $credit_note_help
 * @var array<string, string> $payment_statuses
 * @var array<int, string> $treasury_options
 * @var array<int, string> $new_treasury_options
 * @var array<int, string> $payment_numbers
 * @var string|null $credit_note_number
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('sales/save/' . $sale_info['sale_id'], ['id' => 'sales_edit_form', 'class' => 'app-form']) ?>
    <fieldset id="sale_basic_info">

        <div class="row mb-3">
            <?= form_label(lang('Sales.receipt_number'), 'receipt_number', ['class' => 'col-form-label col-3']) ?>
            <?= anchor('sales/receipt/' . $sale_info['sale_id'], 'POS ' . $sale_info['sale_id'], ['target' => '_blank', 'class' => 'col-form-label col-8', "style" => "text-align: left"]) ?>
        </div>

        <?php if (! empty($credit_note_number)): ?>
            <div class="row mb-3">
                <?= form_label(lang('Numbering.type_credit_note'), 'credit_note_number', ['class' => 'col-form-label col-3']) ?>
                <div class="col-8 col-form-label" id="credit_note_number"><bdi dir="ltr"><?= esc($credit_note_number) ?></bdi></div>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <?= form_label(lang('Sales.date'), 'date', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input(['name' => 'date', 'id' => 'date', 'value' => to_datetime(strtotime($sale_info['sale_time'])), 'class' => 'datetime form-control form-control-sm']) ?>
            </div>
        </div>

        <?php if ($config['invoice_enable']) { ?>
            <div class="row mb-3">
                <?= form_label(lang('Sales.invoice_number'), 'invoice_number', ['class' => 'col-form-label col-3']) ?>
                <div class="col-8">
                    <?php if (!empty($sale_info["invoice_number"]) && isset($sale_info['customer_id']) && !empty($sale_info['email'])): ?>
                        <?= form_input(['name' => 'invoice_number', 'size' => 10, 'value' => $sale_info['invoice_number'], 'id' => 'invoice_number', 'class' => 'form-control form-control-sm']) ?>
                        <a id="send_invoice" href="javascript:void(0);"><?= lang('Sales.send_invoice') ?></a>
                    <?php else: ?>
                        <?= form_input(['name' => 'invoice_number', 'value' => $sale_info['invoice_number'], 'id' => 'invoice_number', 'class' => 'form-control form-control-sm']) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php } ?>

        <?php if ($balance_due) { ?>
            <div class="row mb-3">
                <?= form_label(lang('Sales.payment'), 'payment_types_new', ['class' => 'col-form-label col-3']) ?>
                <div class="col-4">
                    <?= form_dropdown('payment_type_new', $new_payment_options, $payment_type_new, ['id' => 'payment_types_new', 'class' => 'form-select']) ?>
                </div>
                <div class="col-4">
                    <div class="input-group input-group-sm">
                        <?php if (!is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                        <?= form_input(['name' => 'payment_amount_new', 'value' => $payment_amount_new, 'id' => 'payment_amount_new', 'class' => 'form-control form-control-sm', 'aria-label' => lang('Sales.amount_tendered')]) // TODO: potentially we need to add type to be float/decimal/numeric to reduce improper data entry ?>
                        <?php if (is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($new_treasury_options !== []): ?>
                <div class="row mb-3">
                    <?= form_label(lang('Sales.treasury'), 'treasury_id_new', ['class' => 'col-form-label col-3']) ?>
                    <div class="col-8">
                        <?= form_dropdown('treasury_id_new', $new_treasury_options, '0', ['id' => 'treasury_id_new', 'class' => 'form-select form-select-sm']) ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row mb-3 reference-code-input-new d-none">
                <?= form_label(lang('Sales.reference_code'), 'reference_code_new', ['class' => 'col-form-label col-3']) ?>
                <div class="col-8">
                    <?= form_input(['name' => 'reference_code_new', 'id' => 'reference_code_new', 'value' => '', 'class' => 'form-control form-control-sm', 'disabled' => true]) ?>
                </div>
            </div>
        <?php } ?>

        <?php
        $i = 0;
        foreach ($payments as $row) {
        ?>
            <div class="row mb-3">
                <?= form_label(lang('Sales.payment') . (isset($payment_numbers[(int) $row->payment_id]) ? ' ' . esc($payment_numbers[(int) $row->payment_id]) : ''), "payment_types_$i", ['class' => 'col-form-label col-3']) ?>
                <div class="col-4">
                    <?php // No editing of Gift Card payments as it's a complex change ?>
                    <?= form_hidden("payment_id_$i", $row->payment_id) ?>
                    <?= form_hidden("reference_code_$i", $row->reference_code ?? '') ?>
                    <?php if (!empty(strstr($row->payment_type, lang('Sales.giftcard')))): ?>
                        <?= form_input(['name' => "payment_type_$i", 'value' => $row->payment_type, 'id' => "payment_type_$i", 'class' => 'form-control form-control-sm', 'readonly' => 'true']) ?>
                    <?php else: ?>
                        <?= form_dropdown("payment_type_$i", $payment_options, $row->payment_type, ['id' => "payment_types_$i", 'class' => 'form-select']) ?>
                    <?php endif; ?>
                </div>
                <div class="col-4">
                    <div class="input-group input-group-sm">
                        <?php if (!is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                        <?= form_input(['name' => "payment_amount_$i", 'value' => to_currency_no_money($row->payment_amount), 'id' => "payment_amount_$i", 'class' => 'form-control form-control-sm', 'readonly' => 'true', 'aria-label' => lang('Sales.amount_tendered')])    // TODO: add type attribute ?>
                        <?php if (is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Sales.refund'), "refund_types_$i", ['class' => 'col-form-label col-3']) ?>
                <div class="col-4">
                    <?php // No editing of Gift Card payments as it's a complex change ?>
                    <?php if (!empty(strstr($row->payment_type, lang('Sales.giftcard')))): ?>
                        <?= form_input(['name' => "refund_type_$i", 'value' => lang('Sales.cash'), 'id' => "refund_type_$i", 'class' => 'form-control form-control-sm', 'readonly' => 'true']) ?>
                    <?php else: ?>
                        <?= form_dropdown("refund_type_$i", $payment_options, lang('Sales.cash'), ['id' => "refund_types_$i", 'class' => 'form-select']) ?>
                    <?php endif; ?>
                </div>
                <div class="col-4">
                    <div class="input-group input-group-sm">
                        <?php if (!is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                        <?= form_input(['name' => "refund_amount_$i", 'value' => to_currency_no_money($row->cash_refund), 'id' => "refund_amount_$i", 'class' => 'form-control form-control-sm', 'readonly' => 'true', 'aria-label' => lang('Sales.refund')]) ?>
                        <?php if (is_right_side_currency_symbol()): ?>
                            <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (! empty($accounting_on)): ?>
                <div class="row mb-3">
                    <?= form_label(lang('Sales.payment_status') . ' #' . esc($payment_numbers[(int) $row->payment_id] ?? (string) (int) $row->payment_id), "payment_status_$i", ['class' => 'col-form-label col-3']) ?>
                    <div class="col-8">
                        <?= form_dropdown("payment_status_$i", $payment_statuses, $row->payment_status ?? 'completed', ['id' => "payment_status_$i", 'class' => 'form-select form-select-sm']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($treasury_options !== [] && ! empty($row->uses_treasury)): ?>
                <div class="row mb-3">
                    <?= form_label(lang('Sales.treasury') . ' #' . esc($payment_numbers[(int) $row->payment_id] ?? (string) (int) $row->payment_id), "treasury_id_$i", ['class' => 'col-form-label col-3']) ?>
                    <div class="col-8">
                        <?= form_dropdown("treasury_id_$i", $treasury_options, (string) ($row->treasury_id ?? 0), ['id' => "treasury_id_$i", 'class' => 'form-select form-select-sm']) ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php
            ++$i;
        }
        echo form_hidden('number_of_payments', strval($i));
        ?>

        <?php if (! empty($receivable)): ?>
            <div class="row mb-3" id="receivable_figures">
                <span class="col-form-label col-3"><?= lang('Sales.receivable') ?></span>
                <div class="col-8">
                    <table class="table table-sm mb-1">
                        <tbody>
                            <tr><th scope="row"><?= lang('Sales.receivable_total') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->total) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Sales.receivable_paid') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->paid) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Sales.receivable_credit_applied') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->applied) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Sales.receivable_returned') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->returned) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Sales.receivable_open') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->open()) ?></bdi></td></tr>
                            <?php if (bccomp($receivable->excess(), '0', 4) > 0): ?>
                                <tr><th scope="row"><?= lang('Sales.receivable_excess') ?></th><td><bdi dir="ltr"><?= to_currency($receivable->excess()) ?></bdi></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <span class="badge text-bg-primary" id="receivable_status"><?= lang($receivable->paymentState()->languageKey()) ?></span>
                    <?php if ($receivable->returnState()->languageKey() !== null): ?>
                        <span class="badge text-bg-secondary"><?= lang($receivable->returnState()->languageKey()) ?></span>
                    <?php endif; ?>
                    <?php if (! empty($applications)): ?>
                        <ul class="list-unstyled small mt-2 mb-0" id="credit_applications">
                            <?php foreach ($applications as $application): ?>
                                <li>
                                    <?= lang($application->source_type === 'credit_note' ? 'Sales.credit_from_note' : 'Sales.credit_from_excess') ?>
                                    <bdi dir="ltr">#<?= (int) $application->source_id ?></bdi>
                                    &rarr; <bdi dir="ltr">#<?= (int) $application->invoice_sale_id ?></bdi>
                                    <bdi dir="ltr"><?= to_currency($application->amount) ?></bdi>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! empty($issued_lock)): ?>
            <div class="row mb-3" id="issued_lock">
                <span class="col-form-label col-3"><?= lang('Sales.credit_note') ?></span>
                <div class="col-8">
                    <p class="small text-body-secondary mb-2"><?= lang('Sales.credit_note_help_issued') ?></p>
                    <?php if ((int) ($sale_info['sale_type'] ?? 0) === SALE_TYPE_INVOICE): ?>
                        <?php if (! empty($credit_note_help)): ?>
                            <p class="small text-body-secondary mb-0"><?= esc($credit_note_help) ?></p>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="create_credit_note"><?= lang('Sales.credit_note_create') ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ((int) ($sale_info['sale_type'] ?? 0) === SALE_TYPE_INVOICE && (int) ($sale_info['sale_status'] ?? 0) === COMPLETED): ?>
            <div class="row mb-3">
                <?= form_label(lang('Sales.delivery'), 'delivery_status', ['class' => 'col-form-label col-3']) ?>
                <div class="col-8" id="delivery_status">
                    <?php if ((int) ($sale_info['delivered'] ?? 1) === 1): ?>
                        <span class="badge text-bg-success"><?= lang('Sales.delivered') ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-warning"><?= lang('Sales.not_delivered') ?></span>
                        <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="confirm_delivery"><?= lang('Sales.confirm_delivery') ?></button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! empty($journals)): ?>
            <div class="row mb-3">
                <span class="col-form-label col-3"><?= lang('Sales.accounting_entries') ?></span>
                <div class="col-8">
                    <ul class="list-unstyled mb-0" id="sale_journals">
                        <?php foreach ($journals as $journal): ?>
                            <li>
                                <?= anchor('accounting_journals/view/' . (int) $journal->journal_id, esc((string) $journal->number), ['target' => '_blank', 'dir' => 'ltr']) ?>
                                <?= lang($journal->source_type === 'sale_payment' ? 'Sales.entry_payment' : 'Sales.entry_' . $journal->entry_type) ?>
                                <?php if ($journal->status === 'reversed'): ?>
                                    <span class="badge text-bg-secondary"><?= lang('Sales.entry_reversed') ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <?= form_label(lang('Sales.customer'), 'customer_name', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input(['name' => 'customer_name', 'value' => $selected_customer_name, 'id' => 'customer_name', 'class' => 'form-control form-control-sm']) ?>
                <?= form_hidden('customer_id', $selected_customer_id ?? '') ?>
            </div>
        </div>

        <div class="row mb-3">
            <?= form_label(lang('Sales.employee'), 'employee_name', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input(['name' => 'employee_name', 'value' => $selected_employee_name, 'id' => 'employee_name', 'class' => 'form-control form-control-sm']) ?>
                <?= form_hidden('employee_id', $selected_employee_id) ?>
            </div>
        </div>

        <div class="row mb-3">
            <?= form_label(lang('Sales.comment'), 'comment', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_textarea(['name' => 'comment', 'value' => $sale_info['comment'], 'id' => 'comment', 'class' => 'form-control form-control-sm']) ?>
            </div>
        </div>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?php if (!empty($sale_info['email'])): ?>
            $('#send_invoice').click(function(event) {
                if (confirm("<?= lang('Sales.invoice_confirm') . ' ' . $sale_info['email'] ?>")) {
                    $.get("<?= esc("$controller_name/sendPdf/" . $sale_info['sale_id']) ?>",
                        function(response) {
                            dialog_support.hide();
                            $.notify({
                                message: response.message
                            }, {
                                type: response.success ? 'success' : 'danger'
                            })
                        }, 'json'
                    );
                }
            });
        <?php endif; ?>

        <?= view('partial/datepicker_locale') ?>

        const fill_value_customer = function(event, ui) {
            event.preventDefault();
            $("input[name='customer_id']").val(ui.item.value);
            $("input[name='customer_name']").val(ui.item.label);
        };

        $('#customer_name').autocomplete({
            source: "<?= 'customers/suggest' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_value_customer,
            focus: fill_value_customer
        });

        const fill_value_employee = function(event, ui) {
            event.preventDefault();
            $("input[name='employee_id']").val(ui.item.value);
            $("input[name='employee_name']").val(ui.item.label);
        };

        $('#employee_name').autocomplete({
            source: "<?= 'employees/suggest' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_value_employee,
            focus: fill_value_employee
        });

        const referenceCodePaymentTypes = <?= json_encode($reference_code_payment_types ?? []) ?>;

        $('#payment_types_new').on('change', function() {
            const paymentType = $(this).val();
            const needsReferenceCode = referenceCodePaymentTypes.indexOf(paymentType) !== -1;
            if (needsReferenceCode) {
                $('.reference-code-input-new').removeClass('d-none');
                $('#reference_code_new').attr('disabled', false);
            } else {
                $('.reference-code-input-new').addClass('d-none');
                $('#reference_code_new').attr('disabled', true).val('');
            }
        });

        $('#confirm_delivery').click(function() {
            $.post("<?= esc(site_url("{$controller_name}/confirmDelivery/" . $sale_info['sale_id'])) ?>", function(response) {
                dialog_support.hide();
                table_support.handle_submit("<?= esc($controller_name) ?>", response);
            }, 'json');
        });

        <?php if (! empty($issued_lock)): ?>
            $('button#delete').hide();
        <?php endif; ?>

        $('#create_credit_note').click(function() {
            $.post("<?= esc(site_url("{$controller_name}/creditNote/" . $sale_info['sale_id'])) ?>", function(response) {
                if (response.success) {
                    window.location.href = response.redirect;
                } else {
                    $.notify({message: response.message}, {type: 'danger'});
                }
            }, 'json');
        });

        $('button#delete').click(function() {
            dialog_support.hide();
            table_support.do_delete("<?= esc($controller_name); ?>", <?= $sale_info['sale_id'] ?>);
        });

        $('button#restore').click(function() {
            dialog_support.hide();
            table_support.do_restore("<?= esc($controller_name) ?>", <?= $sale_info['sale_id'] ?>);
        });

        $('#sales_edit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);

                        const params = $.param(table_support.query_params());
                        $.get("<?= $controller_name; ?>/search?" + params, function(response) {
                            $("#payment_summary").html(response.payment_summary);
                        }, 'json');
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                invoice_number: {
                    remote: {
                        url: "<?= esc("$controller_name/checkInvoiceNumber") ?>",
                        type: 'POST',
                        data: {
                            'sale_id': <?= $sale_info['sale_id'] ?>,
                            'invoice_number': function() {
                                return $('#invoice_number').val();
                            }
                        }
                    }
                }
            },

            messages: {
                invoice_number: "<?= lang('Sales.invoice_number_duplicate') ?>"
            }
        }, form_support.error));
    });
</script>
