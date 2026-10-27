<?php
/**
 * @var SettlementPlan $plan
 * @var list<object>   $from_treasuries
 * @var list<object>   $to_treasuries
 * @var string         $request_key
 * @var Closure        $amount
 * @var string         $payer_code
 * @var string         $payee_code
 */

use App\Libraries\Accounting\SettlementPlan;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_treasuries/inter_branch/settle', ['id' => 'settle_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="branch" value="<?= (int) $plan->firstBranchId ?>">
    <input type="hidden" name="other" value="<?= (int) $plan->secondBranchId ?>">
    <input type="hidden" name="date" value="<?= esc($plan->date, 'attr') ?>">
    <input type="hidden" name="request_key" value="<?= esc($request_key, 'attr') ?>">

    <p class="mb-2" id="settle_pair">
        <?= esc(lang('Accounting_treasuries.settle_pair', [$plan->firstCode, $plan->secondCode])) ?>
        <span class="text-body-secondary">&middot; <?= esc(lang('Accounting_treasuries.settle_date')) ?> <bdi dir="ltr"><?= esc($plan->date) ?></bdi></span>
    </p>

    <table class="table table-sm table-bordered w-auto" id="settle_figures">
        <thead>
            <tr>
                <th scope="col"><?= lang('Accounting_treasuries.pair_branch') ?></th>
                <th scope="col"><?= lang('Accounting_treasuries.pair_owed') ?></th>
                <th scope="col"><?= lang('Accounting_treasuries.pair_owing') ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <th scope="row"><bdi dir="ltr"><?= esc($plan->firstCode) ?></bdi></th>
                <td><bdi dir="ltr"><?= esc($amount($plan->firstReceivable)) ?></bdi></td>
                <td><bdi dir="ltr"><?= esc($amount($plan->firstPayable)) ?></bdi></td>
            </tr>
            <tr>
                <th scope="row"><bdi dir="ltr"><?= esc($plan->secondCode) ?></bdi></th>
                <td><bdi dir="ltr"><?= esc($amount($plan->secondReceivable)) ?></bdi></td>
                <td><bdi dir="ltr"><?= esc($amount($plan->secondPayable)) ?></bdi></td>
            </tr>
        </tbody>
    </table>

    <?php if (! $plan->agrees): ?>
        <p class="alert alert-danger" id="settle_not_agreed" role="alert"><?= esc(lang('Accounting_treasuries.settle_not_agreed')) ?></p>
    <?php elseif ($plan->kind === null): ?>
        <p class="alert alert-info" id="settle_nothing" role="note"><?= esc(lang('Accounting_treasuries.settle_nothing')) ?></p>
    <?php else: ?>
        <p class="mb-2" id="settle_clears"><?= esc(lang('Accounting_treasuries.settle_clears')) ?></p>

        <?php if ($plan->kind === SettlementPlan::NETTING): ?>
            <p class="alert alert-info" id="settle_netting" role="note"><?= esc(lang('Accounting_treasuries.settle_kind_netting')) ?></p>
        <?php else: ?>
            <p class="alert alert-info" id="settle_cash" role="note"><?= esc(lang('Accounting_treasuries.settle_kind_cash', [$payer_code, $amount($plan->amount), $payee_code])) ?></p>

            <div class="form-grid">
                <div class="field">
                    <?= form_label(lang('Accounting_treasuries.settle_from_treasury', [$payer_code]), 'from_treasury_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="from_treasury_id" id="from_treasury_id" class="form-select form-select-sm">
                            <option value=""></option>
                            <?php foreach ($from_treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <?= form_label(lang('Accounting_treasuries.settle_to_treasury', [$payee_code]), 'to_treasury_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="to_treasury_id" id="to_treasury_id" class="form-select form-select-sm">
                            <option value=""></option>
                            <?php foreach ($to_treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <?= form_label(lang('Accounting_treasuries.note_label'), 'note', ['class' => 'form-label']) ?>
            <div class="field-control">
                <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
            </div>
        </div>
    <?php endif; ?>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const settleable = <?= json_encode($plan->canBeSettled()) ?>;
        const cash = <?= json_encode($plan->kind === SettlementPlan::CASH) ?>;

        $('#settle_form').validate($.extend({
            submitHandler: function(form) {
                if (!settleable) {
                    return false;
                }

                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.reloadPairs();
                        }

                        table_support.handle_submit('accounting_treasuries/inter_branch/settlements', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: cash ? {from_treasury_id: 'required', to_treasury_id: 'required'} : {},

            messages: {
                from_treasury_id: "<?= esc(lang('Accounting_treasuries.settle_treasury_required'), 'js') ?>",
                to_treasury_id: "<?= esc(lang('Accounting_treasuries.settle_treasury_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
