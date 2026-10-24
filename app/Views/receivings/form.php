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
