<?php
/**
 * @var array<string, string> $branches
 * @var list<BackfillFamily>  $families
 * @var int                   $chunk
 */

use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillService;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/backfill/create', ['id' => 'backfill_new_form', 'class' => 'app-form']) ?>
    <fieldset>
        <p class="text-body-secondary"><?= lang('Accounting_backfill.new_intro') ?></p>

        <div class="form-grid">
            <div class="field span-all">
                <?= form_label(lang('Accounting_backfill.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="120">
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_backfill.from'), 'from', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="from" id="from" class="form-control form-control-sm">
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_backfill.to'), 'to', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="to" id="to" class="form-control form-control-sm">
                </div>
            </div>
            <div class="field span-all">
                <?= form_label(lang('Accounting_backfill.branch'), 'branch_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="branch_id" id="branch_id" class="form-select form-select-sm">
                        <?php foreach ($branches as $value => $label): ?>
                            <option value="<?= esc((string) $value, 'attr') ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field span-all">
                <span class="form-label"><?= lang('Accounting_backfill.families') ?></span>
                <div class="field-control">
                    <?php foreach ($families as $family): ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="families[]" id="family_<?= esc($family->value, 'attr') ?>" value="<?= esc($family->value, 'attr') ?>" checked>
                            <label class="form-check-label" for="family_<?= esc($family->value, 'attr') ?>"><?= lang('Accounting_backfill.family_' . $family->value) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_backfill.chunk'), 'chunk_size', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="number" name="chunk_size" id="chunk_size" class="form-control form-control-sm" min="1" max="<?= esc((string) BackfillService::CHUNK_MAX, 'attr') ?>" value="<?= (int) $chunk ?>">
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#backfill_new_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.href = response.redirect;
                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {name: 'required', from: 'required', to: 'required'},

            messages: {
                name: "<?= esc(lang('Accounting_backfill.name_required'), 'js') ?>",
                from: "<?= esc(lang('Accounting_backfill.range_required'), 'js') ?>",
                to: "<?= esc(lang('Accounting_backfill.range_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
