<?php
/**
 * @var int    $stocktakeId
 * @var string $number
 * @var bool   $approved
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('stock_documents/stocktakes/cancel/' . $stocktakeId, ['id' => 'stocktake_cancel_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><bdi dir="ltr"><?= esc($number) ?></bdi></p>
    <p class="small text-body-secondary"><?= lang($approved ? 'Stock_documents.cancel_approved_note' : 'Stock_documents.cancel_counting_note') ?></p>

    <div class="form-grid">
        <div class="field span-all">
            <?= form_label(lang('Stock_documents.reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
            </div>
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#stocktake_cancel_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                reason: 'required'
            },

            messages: {
                reason: "<?= esc(lang('Stock_documents.reason_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
