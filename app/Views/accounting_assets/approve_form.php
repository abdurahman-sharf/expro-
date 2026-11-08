<?php
/**
 * @var DepreciationPreview $preview
 * @var string              $scope
 * @var string              $month
 * @var string              $fingerprint
 */

use App\Libraries\Assets\DepreciationPreview;

$branches = array_map(static fn ($plan): string => $plan->code, $preview->ready());
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_assets/depreciation/approve', ['id' => 'depreciation_approve_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="month" value="<?= esc($month, 'attr') ?>">
    <input type="hidden" name="scope" value="<?= esc($scope, 'attr') ?>">
    <input type="hidden" name="fingerprint" value="<?= esc($fingerprint, 'attr') ?>">
    <fieldset>
        <p><?= esc(lang('Accounting_assets.approve_summary', [$month, implode(', ', $branches)])) ?></p>
        <p class="text-danger"><?= lang('Accounting_assets.approve_warning') ?></p>

        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="confirm" id="confirm" value="1">
            <label class="form-check-label" for="confirm"><?= lang('Accounting_assets.approve_confirm') ?></label>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#depreciation_approve_form').validate($.extend({
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

            rules: {confirm: 'required'},

            messages: {confirm: "<?= esc(lang('Accounting_assets.approve_confirm_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
