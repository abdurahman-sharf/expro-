<?php
/**
 * @var object $expenses_info
 * @var array $payment_options
 * @var array<int, string> $treasury_options
 * @var array<int, string> $branch_options
 * @var int $branch_selected
 * @var array $expense_categories
 * @var array $employees
 * @var string $controller_name
 * @var array $config
 * @var string|null $expense_number
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("expenses/save/$expenses_info->expense_id", ['id' => 'expenses_edit_form', 'class' => 'app-form']) ?>
    <fieldset id="item_basic_info" class="form-grid">

        <?php if ((int) $expenses_info->expense_id > 0) { ?>
            <p class="form-static span-all">
                <?= lang('Expenses.info') ?> &mdash; <?= lang('Expenses.expense_id') ?> <span class="ltr"><?= esc($expenses_info->expense_id) ?></span>
                <?php if (! empty($expense_number)) { ?>
                    &mdash; <?= lang('Numbering.document_number') ?> <span class="ltr"><?= esc($expense_number) ?></span>
                <?php } ?>
            </p>
        <?php } ?>

        <div class="field">
            <?= form_label(lang('Expenses.date'), 'date', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-calendar3" aria-hidden="true"></span></span>
                    <?= form_input([
                        'name'     => 'date',
                        'id'       => 'date',
                        'class'    => 'form-control form-control-sm datetime',
                        'value'    => to_datetime(strtotime($expenses_info->date)),
                        'readonly' => 'readonly'
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.supplier_name'), 'supplier_name', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group">
                    <?= form_input([
                        'name'  => 'supplier_name',
                        'id'    => 'supplier_name',
                        'class'       => 'form-control form-control-sm',
                        'placeholder' => lang('Expenses.start_typing_supplier_name')
                    ]);
                    echo form_input([
                        'type' => 'hidden',
                        'name' => 'supplier_id',
                        'id'   => 'supplier_id'
                    ]) ?>
                    <button type="button" id="remove_supplier_button" class="btn btn-outline-danger d-none" aria-label="<?= lang('Common.delete') ?>">
                        <span class="bi bi-x-lg" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.supplier_tax_code'), 'supplier_tax_code', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'  => 'supplier_tax_code',
                    'id'    => 'supplier_tax_code',
                    'class' => 'form-control form-control-sm',
                    'value' => $expenses_info->supplier_tax_code
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'amount',
                        'id'    => 'amount',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($expenses_info->amount)
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.tax_amount'), 'tax_amount', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'tax_amount',
                        'id'    => 'tax_amount',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($expenses_info->tax_amount)
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.payment'), 'payment_type', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('payment_type', $payment_options, $expenses_info->payment_type, ['class' => 'form-select', 'id' => 'payment_type']) ?>
            </div>
        </div>

        <?php if (($treasury_options ?? []) !== []): ?>
            <div class="field">
                <?= form_label(lang('Expenses.treasury'), 'treasury_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_dropdown('treasury_id', array_map('esc', $treasury_options), (string) ($expenses_info->treasury_id ?? 0), ['class' => 'form-select', 'id' => 'treasury_id']) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (($branch_options ?? []) !== []): ?>
            <div class="field">
                <?= form_label(lang('Expenses.branch'), 'expense_branch_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_dropdown('expense_branch_id', array_map('esc', $branch_options), (string) ($branch_selected ?? 0), ['class' => 'form-select', 'id' => 'expense_branch_id', 'aria-describedby' => 'expense_branch_note']) ?>
                    <div class="form-text" id="expense_branch_note"><?= esc(lang('Expenses.branch_note')) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <?= form_label(lang('Expenses_categories.name'), 'category', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('expense_category_id', $expense_categories, $expenses_info->expense_category_id, ['class' => 'form-select', 'id' => 'category']) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses.employee'), 'employee_id', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?php if ($can_assign_employee): ?>
                    <?= form_dropdown('employee_id', $employees, $expenses_info->employee_id, 'id="employee_id" class="form-select"') ?>
                <?php else: ?>
                    <?= form_hidden('employee_id', $expenses_info->employee_id) ?>
                    <?= form_input(['name' => 'employee_name', 'value' => $employees[$expenses_info->employee_id] ?? '', 'class' => 'form-control', 'readonly' => 'readonly']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Expenses.description'), 'description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control form-control-sm',
                    'value' => $expenses_info->description
                ]) ?>
            </div>
        </div>

        <?php if (!empty($expenses_info->expense_id)) { ?>
            <div class="field field-check">
                <?= form_label(lang('Expenses.is_deleted'), 'deleted', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_checkbox([
                        'name'    => 'deleted',
                        'id'      => 'deleted',
                        'value'   => 1,
                        'checked' => $expenses_info->deleted == 1
                    ]) ?>
                </div>
            </div>
        <?php } ?>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        <?= view('partial/datepicker_locale') ?>

        $('#supplier_name').autocomplete({
            source: '<?= "suppliers/suggest" ?>',
            minChars: 0,
            delay: 10,
            select: function(event, ui) {
                $('#supplier_id').val(ui.item.value);
                $(this).val(ui.item.label);
                $(this).attr('readonly', 'readonly');
                $('#remove_supplier_button').removeClass('d-none');
                return false;
            }
        });

        $('#remove_supplier_button').addClass('d-none');

        $('#remove_supplier_button').click(function() {
            $('#supplier_id').val('');
            $('#supplier_name').removeAttr('readonly');
            $('#supplier_name').val('');
            $(this).addClass('d-none');
        });

        <?php if ($expenses_info->expense_id != -1) { ?>
            $('#supplier_id').val('<?= $expenses_info->supplier_id ?>');
            $('#supplier_name').val('<?= esc($expenses_info->supplier_name, 'js') ?>').attr('readonly', 'readonly');
            $('#remove_supplier_button').removeClass('d-none');
        <?php } ?>

        $('#expenses_edit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            ignore: '',

            rules: {
                expense_category_id: 'required',
                date: {
                    required: true
                },
                amount: {
                    required: true,
                    remote: "<?= "$controller_name/checkNumeric" ?>"
                },
                tax_amount: {
                    remote: "<?= "$controller_name/checkNumeric" ?>"
                }
            },

            messages: {
                expense_category_id: "<?= lang('Expenses_categories.category_name_required') ?>",
                date: {
                    required: "<?= lang('Expenses.date_required') ?>"

                },
                amount: {
                    required: "<?= lang('Expenses.amount_required') ?>",
                    remote: "<?= lang('Expenses.amount_number') ?>"
                },
                tax_amount: {
                    remote: "<?= lang('Expenses.tax_amount_number') ?>"
                }
            }
        }, form_support.error));
    });
</script>
