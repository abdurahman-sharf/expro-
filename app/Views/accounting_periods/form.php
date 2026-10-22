<?php
/**
 * @var object|null $year
 * @var array{name: string, start_date: string, end_date: string} $suggested
 * @var bool $start_locked
 * @var bool $end_locked
 */

$isNew = $year === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_periods/save/' . ($isNew ? NEW_ENTRY : (int) $year->fiscal_year_id), ['id' => 'year_form', 'class' => 'app-form']) ?>
    <fieldset id="year_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_periods.year_name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= esc($isNew ? $suggested['name'] : $year->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_periods.start_date'), 'start_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="<?= esc($isNew ? $suggested['start_date'] : $year->start_date, 'attr') ?>"<?= $start_locked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_periods.end_date'), 'end_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" value="<?= esc($isNew ? $suggested['end_date'] : $year->end_date, 'attr') ?>"<?= $end_locked ? ' readonly' : '' ?>>
                </div>
                <p class="form-text"><?= lang('Accounting_periods.dates_hint') ?></p>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Accounting_periods.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="description" id="description" class="form-control form-control-sm" maxlength="255" value="<?= esc($isNew ? '' : (string) $year->description, 'attr') ?>">
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#year_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_periods', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required',
                start_date: 'required',
                end_date: 'required'
            },

            messages: {
                name: "<?= esc(lang('Accounting_periods.name_required'), 'js') ?>",
                start_date: "<?= esc(lang('Accounting_periods.start_required'), 'js') ?>",
                end_date: "<?= esc(lang('Accounting_periods.end_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
