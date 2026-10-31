<?php
/**
 * @var int $branch_id
 * @var int $location_id
 * @var string $location_name
 * @var string $fingerprint
 * @var bool $allowed
 * @var list<string> $blocks
 * @var list<array{name: string, units: string, cost_before: string, cost_after: string, value_before: string, value_after: string, change: string}> $lines
 * @var string $before
 * @var string $after
 * @var string $change
 * @var list<array{revision: int, date: string, items: int, value: string, standing: bool, journal_id: ?int}> $revisions
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="stock_form_error_box" class="error_message_box" role="alert"></ul>

<p><strong><?= esc($location_name) ?></strong></p>
<p><?= lang('Accounting_periods.opening_stock_reprice_intro') ?></p>

<?php if ($blocks !== []): ?>
    <div class="alert alert-warning" role="alert">
        <ul class="mb-0">
            <?php foreach ($blocks as $block): ?>
                <li><?= esc($block) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="acc-lines-wrap">
    <table class="table acc-lines" id="reprice_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Accounting_periods.opening_stock_item') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_units') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_cost_before') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_cost_after') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_value_before') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_value_after') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_change') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_item'), 'attr') ?>"><?= esc($line['name']) ?></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_units'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['units']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_cost_before'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['cost_before']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_cost_after'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['cost_after']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_value_before'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['value_before']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_value_after'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['value_after']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_change'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($line['change']) ?></bdi></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="4"><?= lang('Accounting_periods.opening_stock_reprice_total') ?></th>
                <td class="acc-amount"><bdi dir="ltr"><?= esc($before) ?></bdi></td>
                <td class="acc-amount"><bdi dir="ltr"><?= esc($after) ?></bdi></td>
                <td class="acc-amount"><bdi dir="ltr"><?= esc($change) ?></bdi></td>
            </tr>
        </tfoot>
    </table>
</div>

<h3 class="h6 mt-3"><?= lang('Accounting_periods.opening_stock_history') ?></h3>
<div class="acc-lines-wrap">
    <table class="table acc-lines" id="revisions_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Accounting_periods.opening_stock_revision') ?></th>
                <th scope="col"><?= lang('Accounting_journals.date') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_items') ?></th>
                <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_value') ?></th>
                <th scope="col"><?= lang('Accounting_periods.opening_stock_status') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($revisions as $revision): ?>
                <tr>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_revision'), 'attr') ?>">
                        <bdi dir="ltr"><?= (int) $revision['revision'] ?></bdi>
                        <?php if ($revision['journal_id'] !== null): ?>
                            <a href="<?= esc(base_url('accounting_journals/view/' . (int) $revision['journal_id']), 'attr') ?>"><?= esc(lang('Accounting_periods.opening_view_journal')) ?></a>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(lang('Accounting_journals.date'), 'attr') ?>"><?= esc($revision['date']) ?></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_items'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= (int) $revision['items'] ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_value'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($revision['value']) ?></bdi></td>
                    <td data-label="<?= esc(lang('Accounting_periods.opening_stock_status'), 'attr') ?>"><?= esc(lang($revision['standing'] ? 'Accounting_periods.opening_stock_revision_standing' : 'Accounting_periods.opening_stock_revision_reversed')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($allowed): ?>
    <?= form_open('accounting_periods/opening/stock/reprice', ['id' => 'opening_stock_reprice_form', 'class' => 'app-form']) ?>
        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
        <input type="hidden" name="location_id" value="<?= $location_id ?>">
        <input type="hidden" name="plan" value="<?= esc($fingerprint, 'attr') ?>">
        <fieldset>
            <div class="form-grid">
                <div class="field span-all">
                    <?= form_label(lang('Accounting_periods.reopen_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
                    </div>
                </div>
            </div>
        </fieldset>
    <?= form_close() ?>

    <script type="text/javascript">
        $(document).ready(function() {
            $('#opening_stock_reprice_form').validate($.extend({}, form_support.error, {
                submitHandler: function(form) {
                    $(form).ajaxSubmit({
                        success: function(response) {
                            if (response.success) {
                                dialog_support.hide();
                                window.location.href = response.redirect;
                                return;
                            }
                            $('#stock_form_error_box').empty().append($('<li></li>').text(response.message));
                        },
                        dataType: 'json'
                    });
                },

                errorLabelContainer: '#stock_form_error_box',

                rules: {reason: 'required'},

                messages: {
                    reason: "<?= esc(lang('Accounting_periods.reason_required'), 'js') ?>"
                }
            }));
        });
    </script>
<?php else: ?>
    <script type="text/javascript">
        $(document).ready(function() {
            $('.modal-footer [data-dialog-button="submit"]').prop('disabled', true);
        });
    </script>
<?php endif; ?>
