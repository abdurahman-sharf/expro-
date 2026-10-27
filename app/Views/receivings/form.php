<?php
/**
 * @var array $receiving_info
 * @var string $selected_supplier_name
 * @var int $selected_supplier_id
 * @var array $employees
 * @var string $controller_name
 * @var bool $can_assign_employee
 * @var bool $locked
 * @var list<object> $journals
 * @var bool $takes_payments
 * @var list<object> $payments
 * @var array<string, string> $payment_statuses
 * @var array<string, string> $payment_options
 * @var array<int, string> $treasury_options
 * @var array<int, string> $new_treasury_options
 * @var App\Libraries\Accounting\PurchaseFigures|null $payable
 * @var string $credit
 * @var list<object> $applications
 * @var bool $can_debit_note
 * @var array<int, string> $paymentNumbers
 * @var string|null $documentLabel
 * @var string|null $documentNumber
 * @var App\Libraries\Accounting\InvoiceDiscount $invoiceDiscount
 * @var string $invoiceDiscountShare
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("receivings/save/" . $receiving_info['receiving_id'], ['id' => 'receivings_edit_form', 'class' => 'app-form']) ?>
    <fieldset id="receiving_basic_info">

        <?php if ($locked): ?>
            <div class="alert alert-info" id="posted_lock" role="note"><?= lang('Receivings.posted_lock') ?></div>
        <?php endif; ?>

        <div class="row mb-3">
            <span class="col-form-label col-3"><?= lang('Receivings.receipt_number') ?></span>
            <?= anchor('receivings/receipt/' . $receiving_info['receiving_id'], 'RECV ' . $receiving_info['receiving_id'], ['target' => '_blank', 'class' => 'col-form-label col-8', "style" => "text-align: left"]) ?>
        </div>

        <?php if (! empty($documentNumber)): ?>
            <div class="row mb-3">
                <span class="col-form-label col-3"><?= esc($documentLabel) ?></span>
                <div class="col-8 col-form-label" id="document_number"><bdi dir="ltr"><?= esc($documentNumber) ?></bdi></div>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <?= form_label(lang('Receivings.date'), 'datetime', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input([
                    'name'     => 'date',
                    'value'    => to_datetime(strtotime($receiving_info['receiving_time'])),
                    'id'       => 'datetime',
                    'class'    => 'datetime form-control form-control-sm',
                    'readonly' => 'readonly'
                ]) ?>
            </div>
        </div>

        <div class="row mb-3">
            <?= form_label(lang('Receivings.supplier'), 'supplier_name', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input(['name' => 'supplier_name', 'value' => $selected_supplier_name, 'id' => 'supplier_name', 'class' => 'form-control form-control-sm'] + ($locked ? ['readonly' => 'readonly'] : [])) ?>
                <?= form_hidden('supplier_id', $selected_supplier_id ?? '') ?>
            </div>
        </div>

        <div class="row mb-3">
            <?= form_label(lang('Receivings.reference'), 'reference', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_input(['name' => 'reference', 'value' => $receiving_info['reference'], 'id' => 'reference', 'class' => 'form-control form-control-sm']) ?>
            </div>
        </div>

        <?php if (bccomp($invoiceDiscountShare, '0', 4) > 0): ?>
            <div class="row mb-3" id="invoice_discount_info">
                <span class="col-form-label col-3"><?= lang($receiving_info['receiving_type'] === 'return' ? 'Receivings.invoice_discount_share' : 'Receivings.invoice_discount') ?></span>
                <span class="col-form-label col-8" dir="ltr" style="text-align: left"><?= esc(to_currency($invoiceDiscountShare)) ?><?= $invoiceDiscount->type === PERCENT && ! $invoiceDiscount->isNone() ? esc(' (' . to_decimals($invoiceDiscount->value) . '%)') : '' ?></span>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <?= form_label(lang('Receivings.employee'), $can_assign_employee ? 'employee_id' : 'employee_name', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?php if ($can_assign_employee): ?>
                    <?= form_dropdown('employee_id', $employees, $receiving_info['employee_id'], 'id="employee_id" class="form-select"') ?>
                <?php else: ?>
                    <?= form_hidden('employee_id', $receiving_info['employee_id']) ?>
                    <?= form_input(['name' => 'employee_name', 'id' => 'employee_name', 'value' => $employees[$receiving_info['employee_id']] ?? '', 'class' => 'form-control form-control-sm', 'readonly' => 'readonly']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-3">
            <?= form_label(lang('Receivings.comments'), 'comment', ['class' => 'col-form-label col-3']) ?>
            <div class="col-8">
                <?= form_textarea(['name' => 'comment', 'value' => $receiving_info['comment'], 'id' => 'comment', 'class' => 'form-control form-control-sm']) ?>
            </div>
        </div>

        <?php if ($takes_payments): ?>
            <?php foreach (array_values($payments) as $i => $row): ?>
                <div class="row mb-3 supplier-payment">
                    <?= form_label(lang('Receivings.payment') . ' #' . esc($paymentNumbers[(int) $row->payment_id] ?? (string) (int) $row->payment_id), 'payment_types_' . $i, ['class' => 'col-form-label col-3']) ?>
                    <div class="col-5">
                        <?= form_hidden('payment_id_' . $i, $row->payment_id) ?>
                        <?= form_dropdown('payment_type_' . $i, $payment_options, $row->payment_type, ['id' => 'payment_types_' . $i, 'class' => 'form-select form-select-sm']) ?>
                    </div>
                    <div class="col-3">
                        <?= form_input(['name' => 'payment_amount_' . $i, 'value' => to_currency_no_money($row->payment_amount), 'id' => 'payment_amount_' . $i, 'class' => 'form-control form-control-sm', 'aria-label' => lang('Receivings.payment_amount'), 'inputmode' => 'decimal', 'dir' => 'ltr']) ?>
                    </div>
                    <?php if ($payment_statuses !== []): ?>
                        <div class="col-5 offset-3 mt-2">
                            <?= form_dropdown('payment_status_' . $i, $payment_statuses, $row->payment_status ?? 'completed', ['id' => 'payment_status_' . $i, 'class' => 'form-select form-select-sm', 'aria-label' => lang('Sales.payment_status')]) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($treasury_options !== []): ?>
                        <div class="col-9 offset-3 mt-2">
                            <?= form_dropdown('treasury_id_' . $i, $treasury_options, (string) ($row->treasury_id ?? 0), ['id' => 'treasury_id_' . $i, 'class' => 'form-select form-select-sm', 'aria-label' => lang('Receivings.treasury')]) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?= form_hidden('number_of_payments', (string) count($payments)) ?>

            <div class="row mb-3" id="new_supplier_payment">
                <?= form_label(lang('Receivings.payment_new'), 'payment_types_new', ['class' => 'col-form-label col-3']) ?>
                <div class="col-5">
                    <?= form_dropdown('payment_type_new', [PAYMENT_TYPE_UNASSIGNED => lang('Common.none_selected_text')] + $payment_options, PAYMENT_TYPE_UNASSIGNED, ['id' => 'payment_types_new', 'class' => 'form-select form-select-sm']) ?>
                </div>
                <div class="col-3">
                    <?= form_input(['name' => 'payment_amount_new', 'value' => $payable === null ? '' : to_currency_no_money($payable->open()), 'id' => 'payment_amount_new', 'class' => 'form-control form-control-sm', 'aria-label' => lang('Receivings.payment_amount'), 'inputmode' => 'decimal', 'dir' => 'ltr']) ?>
                </div>
                <?php if ($payment_statuses !== []): ?>
                    <div class="col-5 offset-3 mt-2">
                        <?= form_dropdown('payment_status_new', $payment_statuses, 'completed', ['id' => 'payment_status_new', 'class' => 'form-select form-select-sm', 'aria-label' => lang('Sales.payment_status')]) ?>
                    </div>
                <?php endif; ?>
                <?php if ($new_treasury_options !== []): ?>
                    <div class="col-9 offset-3 mt-2">
                        <?= form_dropdown('treasury_id_new', $new_treasury_options, '0', ['id' => 'treasury_id_new', 'class' => 'form-select form-select-sm', 'aria-label' => lang('Receivings.treasury')]) ?>
                    </div>
                <?php endif; ?>
                <?php if ($payment_statuses !== []): ?>
                    <p class="small text-body-secondary col-8 offset-3 mb-0 mt-1"><?= lang('Receivings.payments_note') ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($payable)): ?>
            <div class="row mb-3" id="payable_figures">
                <span class="col-form-label col-3"><?= lang('Receivings.payable') ?></span>
                <div class="col-8">
                    <table class="table table-sm mb-1">
                        <tbody>
                            <tr><th scope="row"><?= lang('Receivings.payable_total') ?></th><td><bdi dir="ltr"><?= to_currency($payable->total) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Receivings.payable_paid') ?></th><td><bdi dir="ltr"><?= to_currency($payable->paid) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Receivings.payable_credit_applied') ?></th><td><bdi dir="ltr"><?= to_currency($payable->applied) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Receivings.payable_returned') ?></th><td><bdi dir="ltr"><?= to_currency($payable->returned) ?></bdi></td></tr>
                            <tr><th scope="row"><?= lang('Receivings.payable_open') ?></th><td><bdi dir="ltr"><?= to_currency($payable->open()) ?></bdi></td></tr>
                            <?php if (bccomp($payable->excess(), '0', 4) > 0): ?>
                                <tr><th scope="row"><?= lang('Receivings.payable_excess') ?></th><td><bdi dir="ltr"><?= to_currency($payable->excess()) ?></bdi></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <span class="badge text-bg-primary" id="payable_status"><?= lang($payable->paymentState()->languageKey()) ?></span>
                    <?php if ($payable->returnState()->languageKey() !== null): ?>
                        <span class="badge text-bg-secondary"><?= lang($payable->returnState()->languageKey()) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! empty($applications)): ?>
            <div class="row mb-3">
                <span class="col-form-label col-3"><?= lang('Receivings.credit_applications') ?></span>
                <div class="col-8">
                    <ul class="list-unstyled small mb-0" id="credit_applications">
                        <?php foreach ($applications as $application): ?>
                            <li>
                                <?= lang($application->source_type === 'debit_note' ? 'Receivings.credit_from_note' : 'Receivings.credit_from_excess') ?>
                                <bdi dir="ltr">#<?= (int) $application->source_id ?></bdi>
                                &rarr; <bdi dir="ltr">#<?= (int) $application->invoice_receiving_id ?></bdi>
                                <bdi dir="ltr"><?= to_currency($application->amount) ?></bdi>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($can_debit_note): ?>
            <div class="row mb-3" id="debit_note_box">
                <span class="col-form-label col-3"><?= lang('Receivings.debit_note') ?></span>
                <div class="col-8">
                    <p class="small text-body-secondary mb-2"><?= lang('Receivings.debit_note_help') ?></p>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="create_debit_note"><?= lang('Receivings.debit_note_create') ?></button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! empty($journals)): ?>
            <div class="row mb-3">
                <span class="col-form-label col-3"><?= lang('Receivings.journals') ?></span>
                <div class="col-8">
                    <ul class="list-unstyled mb-0" id="receiving_journals">
                        <?php foreach ($journals as $journal): ?>
                            <li>
                                <?= anchor('accounting_journals/view/' . (int) $journal->journal_id, esc((string) $journal->number), ['target' => '_blank', 'dir' => 'ltr']) ?>
                                <span class="text-secondary"><?= esc($journal->journal_date) ?> · <?= esc(lang('Accounting.source_' . $journal->source_type)) ?> · <?= esc(to_currency($journal->total_debit)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/datepicker_locale') ?>

        const fill_value = function(event, ui) {
            event.preventDefault();
            $("input[name='supplier_id']").val(ui.item.value);
            $("input[name='supplier_name']").val(ui.item.label);
        };

        <?php if (! $locked): ?>
        $('#supplier_name').autocomplete({
            source: "<?= 'suppliers/suggest' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_value,
            focus: fill_value
        });
        <?php endif; ?>

        $('#create_debit_note').on('click', function() {
            const button = $(this);

            button.prop('disabled', true);
            $.post("<?= esc(site_url('receivings/debitNote/' . $receiving_info['receiving_id'])) ?>", function(response) {
                if (response.success) {
                    window.location.href = response.redirect;
                    return;
                }
                button.prop('disabled', false);
                $('#error_message_box').empty().append($('<li></li>').text(response.message));
            }, 'json').fail(function() {
                button.prop('disabled', false);
            });
        });

        $('button#delete').click(function() {
            dialog_support.hide();
            table_support.do_delete("<?= esc($controller_name) ?>", <?= $receiving_info['receiving_id'] ?>);
        });

        $('#receivings_edit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            }
        }, form_support.error));
    });
</script>
