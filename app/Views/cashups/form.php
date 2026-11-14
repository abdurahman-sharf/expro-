<?php
/**
 * @var object $cash_ups_info
 * @var array  $employees
 * @var bool   $accounting_on
 * @var string $expected_cash
 * @var string $controller_name
 * @var array  $config
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('cashups/save/' . $cash_ups_info->cashup_id, ['id' => 'cashups_edit_form', 'class' => 'app-form'])    // TODO: String Interpolation?>
    <fieldset id="item_basic_info" class="form-grid">

        <?php if ((int) $cash_ups_info->cashup_id > 0) { ?>
            <p class="form-static span-all">
                <?= lang('Cashups.info') ?> &mdash; <?= lang('Cashups.id') ?> <span class="ltr"><?= esc($cash_ups_info->cashup_id) ?></span>
            </p>
        <?php } ?>

        <div class="field">
            <?= form_label(lang('Cashups.open_date'), 'open_date', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text">
                        <span class="bi bi-calendar3" aria-hidden="true"></span>
                    </span>
                    <?= form_input([
                        'name'  => 'open_date',
                        'id'    => 'open_date',
                        'class' => 'form-control form-control-sm datepicker',
                        'value' => to_datetime(strtotime($cash_ups_info->open_date)),
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.open_employee'), 'open_employee_id', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('open_employee_id', $employees, $cash_ups_info->open_employee_id, 'id="open_employee_id" class="form-select"') ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.open_amount_cash'), 'open_amount_cash', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'open_amount_cash',
                        'id'    => 'open_amount_cash',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->open_amount_cash),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.transfer_amount_cash'), 'transfer_amount_cash', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'transfer_amount_cash',
                        'id'    => 'transfer_amount_cash',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->transfer_amount_cash),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.close_date'), 'close_date', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text">
                        <span class="bi bi-calendar3" aria-hidden="true"></span>
                    </span>
                    <?= form_input([
                        'name'  => 'close_date',
                        'id'    => 'close_date',
                        'class' => 'form-control form-control-sm datepicker',
                        'value' => to_datetime(strtotime($cash_ups_info->close_date)),
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.close_employee'), 'close_employee_id', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('close_employee_id', $employees, $cash_ups_info->close_employee_id, 'id="close_employee_id" class="form-select"') ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.closed_amount_cash'), 'closed_amount_cash', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'closed_amount_cash',
                        'id'    => 'closed_amount_cash',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_cash),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (! empty($accounting_on) && isset($expected_cash)): ?>
            <div class="field span-all">
                <span class="form-label"><?= lang('Cashups.expected_cash') ?></span>
                <div class="field-control">
                    <span class="form-control-plaintext"><bdi dir="ltr"><?= esc(to_currency($expected_cash)) ?></bdi></span>
                    <?php if (($cash_ups_info->cash_difference ?? null) !== null): ?>
                        <span class="form-control-plaintext"><?= lang('Cashups.difference') ?>: <bdi dir="ltr"><?= esc(to_currency($cash_ups_info->cash_difference)) ?></bdi></span>
                    <?php endif; ?>
                    <p class="form-text mb-0"><?= lang('Cashups.difference_note') ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="field field-check span-all">
            <?= form_label(lang('Cashups.note'), 'note', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_checkbox([
                    'name'    => 'note',
                    'id'      => 'note',
                    'value'   => 0,
                    'checked' => $cash_ups_info->note === 1,
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.closed_amount_due'), 'closed_amount_due', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'closed_amount_due',
                        'id'    => 'closed_amount_due',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_due),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.closed_amount_card'), 'closed_amount_card', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'closed_amount_card',
                        'id'    => 'closed_amount_card',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_card),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.closed_amount_check'), 'closed_amount_check', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'closed_amount_check',
                        'id'    => 'closed_amount_check',
                        'class' => 'form-control form-control-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_check),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Cashups.closed_amount_total'), 'closed_amount_total', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'     => 'closed_amount_total',
                        'id'       => 'closed_amount_total',
                        'readonly' => 'true',
                        'class'    => 'form-control form-control-sm',
                        'value'    => to_currency_no_money($cash_ups_info->closed_amount_total),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Cashups.description'), 'description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control form-control-sm',
                    'value' => $cash_ups_info->description,
                ]) ?>
            </div>
        </div>

        <?php if (! empty($cash_ups_info->cashup_id)) { ?>
            <div class="field field-check">
                <?= form_label(lang('Cashups.is_deleted'), 'deleted', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_checkbox([
                        'name'    => 'deleted',
                        'id'      => 'deleted',
                        'value'   => 1,
                        'checked' => $cash_ups_info->deleted === 1,
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

        flatpickr('#open_date, #close_date', pickerconfig({
            minDate: "<?= date($config['dateformat'] . ' ' . esc($config['timeformat'], 'js'), mktime(0, 0, 0, 1, 1, 2010)) ?>"
        }));

        $('#open_amount_cash, #transfer_amount_cash, #closed_amount_cash, #closed_amount_due, #closed_amount_card, #closed_amount_check').keyup(function() {
            $.post("<?= esc("{$controller_name}/ajax_cashup_total") ?>", {
                    'open_amount_cash': $('#open_amount_cash').val(),
                    'transfer_amount_cash': $('#transfer_amount_cash').val(),
                    'closed_amount_due': $('#closed_amount_due').val(),
                    'closed_amount_cash': $('#closed_amount_cash').val(),
                    'closed_amount_card': $('#closed_amount_card').val(),
                    'closed_amount_check': $('#closed_amount_check').val()
                },
                function(response) {
                    $('#closed_amount_total').val(response.total);
                },
                'json'
            );
        });

        const submit_form = function() {
            $(this).ajaxSubmit({
                success: function(response) {
                    dialog_support.hide();
                    table_support.handle_submit('<?= esc('cashups') ?>', response);
                },
                dataType: 'json'
            });
        };

        $('#cashups_edit_form').validate($.extend({
            submitHandler: function(form) {
                submit_form.call(form);
            },
            rules: {

            },
            messages: {
                open_date: {
                    required: '<?= lang('Cashups.date_required') ?>'

                },
                close_date: {
                    required: '<?= lang('Cashups.date_required') ?>'

                },
                amount: {
                    required: '<?= lang('Cashups.amount_required') ?>',
                    number: '<?= lang('Cashups.amount_number') ?>'
                }
            }
        }, form_support.error));
    });
</script>
