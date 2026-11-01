<?php
/**
 * @var int    $request_id
 * @var string $number
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('stock_documents/requests/cancel/' . $request_id, ['id' => 'request_cancel_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><bdi dir="ltr"><?= esc($number) ?></bdi></p>
    <p class="small text-body-secondary"><?= lang('Stock_documents.cancel_request_note') ?></p>

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
        $('#request_cancel_form').validate($.extend({
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
