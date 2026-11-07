<?php
/**
 * @var object       $stocktake
 * @var list<object> $lines
 * @var list<object> $journals
 * @var string       $number
 * @var string       $digest
 * @var bool         $canManage
 * @var bool         $canApprove
 * @var string       $active
 * @var bool         $requestsOn
 */
$counting = $stocktake->status === 'counting';
$quantity = static fn (?string $value): string => $value === null ? '' : rtrim(rtrim(bcadd($value, '0', 3), '0'), '.');
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const base = 'stock_documents/stocktakes/';
        const id = <?= (int) $stocktake->stocktake_id ?>;

        const send = function(path, data, reload) {
            $.ajax({
                type: 'POST',
                url: base + path + '/' + id,
                data: data,
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success && reload) {
                        window.location.reload();
                    }
                }
            });
        };

        $(document).on('click', '.stocktake-dialog', function() {
            dialog_support.init(this);
        });

        $('#counts_form').on('submit', function(event) {
            event.preventDefault();
            send('count', $(this).serialize(), true);
        });

        const unsaved = function() {
            const typed = $('#counts_form input[name^="counted["]').filter(function() {
                return $(this).val().trim() !== $(this).attr('data-saved');
            });

            if (typed.length > 0) {
                toast_support.show("<?= esc(lang('Stock_documents.save_counts_first'), 'js') ?>", 'danger');
            }

            return typed.length > 0;
        };

        $('#stocktake_approve').on('click', function() {
            if (!unsaved() && window.confirm("<?= esc(lang('Stock_documents.approve_confirm'), 'js') ?>")) {
                send('approve', {digest: "<?= esc($digest, 'js') ?>"}, true);
            }
        });

        $('.stocktake-remove').on('click', function() {
            if (!unsaved()) {
                send('remove_item', {item_id: $(this).data('item')}, true);
            }
        });

        $('#add_item_name').autocomplete({
            source: 'stock_documents/items',
            minChars: 0,
            delay: 100,
            select: function(event, ui) {
                if (!unsaved()) {
                    send('add_items', {item_ids: [ui.item.value]}, true);
                }

                return false;
            }
        });
    });
</script>

<?= view('stock_documents/tabs', ['active' => $active, 'requestsOn' => $requestsOn]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_documents.stocktake') ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/stocktakes'), 'attr') ?>"><?= lang('Stock_documents.back') ?></a>
    <a class="btn btn-outline-secondary btn-sm" id="stocktake_sheet_link" target="_blank" href="<?= esc(base_url('stock_documents/stocktakes/sheet/' . (int) $stocktake->stocktake_id), 'attr') ?>"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.count_sheet') ?></a>
    <?php if ($counting && $canApprove): ?>
        <button type="button" class="btn btn-success btn-sm" id="stocktake_approve"><?= lang('Stock_documents.approve') ?></button>
    <?php endif; ?>
    <?php if ($stocktake->status !== 'cancelled' && ($stocktake->status === 'approved' ? $canApprove : $canManage)): ?>
        <button class="btn btn-outline-danger btn-sm modal-dlg stocktake-dialog" id="stocktake_cancel" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/stocktakes/cancel_form/' . (int) $stocktake->stocktake_id), 'attr') ?>"><?= lang('Stock_documents.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="stocktake_details">
    <dt class="col-sm-3"><?= lang('Stock_documents.status') ?></dt>
    <dd class="col-sm-9"><span class="badge <?= $stocktake->status === 'approved' ? 'text-bg-success' : ($counting ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= lang('Stock_documents.status_' . $stocktake->status) ?></span><?= $stocktake->status === 'cancelled' ? ' ' . esc((string) $stocktake->cancel_reason) : '' ?></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.location') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $stocktake->location_name) ?> <bdi dir="ltr">(<?= esc((string) $stocktake->branch_code) ?>)</bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.count_date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $stocktake->count_date) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.started_at') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $stocktake->started_at) ?></bdi></dd>
    <?php if ((string) $stocktake->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $stocktake->note) ?></dd>
    <?php endif; ?>
</dl>

<?php if ($counting): ?>
    <p class="small text-body-secondary"><?= lang('Stock_documents.frozen_note') ?></p>
<?php endif; ?>

<?php if ($counting && $canManage): ?>
    <?= form_open('stock_documents/stocktakes/count/' . (int) $stocktake->stocktake_id, ['id' => 'counts_form', 'class' => 'app-form']) ?>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="stocktake_lines">
        <thead>
            <tr>
                <th scope="col"><?= lang('Stock_documents.item') ?></th>
                <th scope="col" class="text-end"><?= lang('Stock_documents.system_quantity') ?></th>
                <th scope="col" class="text-end"><?= lang('Stock_documents.counted_quantity') ?></th>
                <th scope="col" class="text-end"><?= lang('Stock_documents.difference') ?></th>
                <?php if ($stocktake->status === 'approved'): ?>
                    <th scope="col" class="text-end"><?= lang('Stock_documents.unit_cost') ?></th>
                <?php endif; ?>
                <?php if ($counting && $canManage): ?>
                    <th scope="col"><span class="visually-hidden"><?= lang('Stock_documents.remove_line') ?></span></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($lines === []): ?>
                <tr><td colspan="6" class="text-body-secondary" id="stocktake_lines_empty"><?= lang('Stock_documents.no_items_yet') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($lines as $line): ?>
                <?php
                $difference = $stocktake->status === 'approved' ? $line->difference : ($line->counted_quantity === null ? null : bcsub((string) $line->counted_quantity, (string) $line->system_quantity, 3));
                ?>
                <tr id="stocktake_line_<?= (int) $line->item_id ?>">
                    <td><?= esc((string) $line->item_name) ?><?= (string) $line->item_number !== '' ? ' <small class="text-body-secondary" dir="ltr">' . esc((string) $line->item_number) . '</small>' : '' ?></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc($quantity((string) $line->system_quantity)) ?></bdi></td>
                    <td class="text-end">
                        <?php if ($counting && $canManage): ?>
                            <?php $saved = esc($quantity($line->counted_quantity === null ? null : (string) $line->counted_quantity), 'attr'); ?>
                            <input type="hidden" name="seen[<?= (int) $line->item_id ?>]" value="<?= $saved ?>">
                            <input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off" name="counted[<?= (int) $line->item_id ?>]" aria-label="<?= esc(lang('Stock_documents.counted_quantity') . ' ' . $line->item_name, 'attr') ?>" value="<?= $saved ?>" data-saved="<?= $saved ?>">
                        <?php else: ?>
                            <bdi dir="ltr"><?= esc($quantity($line->counted_quantity === null ? null : (string) $line->counted_quantity)) ?></bdi>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><bdi dir="ltr"><?= $difference === null ? '' : esc((bccomp((string) $difference, '0', 3) > 0 ? '+' : '') . $quantity((string) $difference)) ?></bdi></td>
                    <?php if ($stocktake->status === 'approved'): ?>
                        <td class="text-end"><bdi dir="ltr"><?= $line->unit_cost === null ? '' : to_currency($line->unit_cost) ?></bdi></td>
                    <?php endif; ?>
                    <?php if ($counting && $canManage): ?>
                        <td><button type="button" class="btn btn-outline-danger btn-sm stocktake-remove" data-item="<?= (int) $line->item_id ?>" aria-label="<?= esc(lang('Stock_documents.remove_line'), 'attr') ?>"><span class="bi bi-x" aria-hidden="true"></span></button></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($counting && $canManage): ?>
        <button type="submit" class="btn btn-primary btn-sm" id="counts_save"><?= lang('Stock_documents.save_counts') ?></button>
    <?= form_close() ?>

    <div class="mt-3">
        <label class="form-label" for="add_item_name"><?= lang('Stock_documents.add_item') ?></label>
        <input type="text" class="form-control form-control-sm" id="add_item_name" autocomplete="off">
    </div>
<?php endif; ?>

<?php if ($journals !== []): ?>
    <section class="mt-4" aria-labelledby="stocktake_journals_title">
        <h2 id="stocktake_journals_title" class="h6"><?= lang('Stock_documents.journals') ?></h2>
        <ul class="list-unstyled" id="stocktake_journals">
            <?php foreach ($journals as $journal): ?>
                <li>
                    <?= anchor('accounting_journals/view/' . (int) $journal->journal_id, esc((string) $journal->number), ['target' => '_blank', 'dir' => 'ltr']) ?>
                    <span class="text-secondary"><?= esc($journal->journal_date) ?> · <?= esc(lang('Accounting.source_' . $journal->source_type)) ?> · <?= esc(to_currency($journal->total_debit)) ?> · <?= esc($journal->status) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?= view('partial/footer') ?>
