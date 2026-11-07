<?php
/**
 * @var array<int, string> $locations
 * @var string|null        $number
 * @var string             $today
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('stock_documents/stocktakes/save', ['id' => 'stocktake_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Stock_documents.stocktake_form_note') ?></p>

    <fieldset id="stocktake_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Stock_documents.number'), 'stocktake_number', ['class' => 'form-label']) ?>
                <div class="field-control"><span class="form-control-plaintext" id="stocktake_number"><bdi dir="ltr"><?= esc($number ?? lang('Stock_documents.number_next')) ?></bdi></span></div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.location'), 'location_id', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <select name="location_id" id="location_id" class="form-select form-select-sm">
                        <?php foreach ($locations as $locationId => $name): ?>
                            <option value="<?= (int) $locationId ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.count_date'), 'count_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="count_date" id="count_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>">
                </div>
            </div>

            <div class="field span-all">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="all_items" id="all_items" value="1">
                    <label class="form-check-label" for="all_items"><?= lang('Stock_documents.all_items') ?></label>
                </div>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Stock_documents.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#stocktake_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.href = 'stock_documents/stocktakes/view/' + response.id;
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                count_date: 'required'
            },

            messages: {
                count_date: "<?= esc(lang('Stock_documents.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
