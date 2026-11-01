<?php
/**
 * @var object                              $permit
 * @var list<object>                        $lines
 * @var list<object>                        $journals
 * @var string                              $number
 * @var App\Libraries\Stock\StockPermitType $type
 * @var bool                                $can_manage
 */
use App\Libraries\Stock\StockPermitType;

$withCost = $type !== StockPermitType::Transfer;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.permit-dialog', function() {
            dialog_support.init(this);
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang($type->labelKey()) ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/permits'), 'attr') ?>"><?= lang('Stock_documents.back') ?></a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.print') ?></button>
    <?php if ($can_manage && $permit->status === 'posted'): ?>
        <button class="btn btn-outline-primary btn-sm modal-dlg permit-dialog" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/edit/' . (int) $permit->permit_id), 'attr') ?>"><?= lang('Stock_documents.edit') ?></button>
        <button class="btn btn-outline-danger btn-sm modal-dlg permit-dialog" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/cancel_form/' . (int) $permit->permit_id), 'attr') ?>"><?= lang('Stock_documents.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="permit_details">
    <dt class="col-sm-3"><?= lang('Stock_documents.status') ?></dt>
    <dd class="col-sm-9"><?= $permit->status === 'posted' ? '<span class="badge text-bg-success">' . lang('Stock_documents.status_posted') . '</span>' : '<span class="badge text-bg-secondary">' . lang('Stock_documents.status_cancelled') . '</span> ' . esc((string) $permit->cancel_reason) ?></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $permit->permit_date) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang($type === StockPermitType::Transfer ? 'Stock_documents.from_location' : 'Stock_documents.location') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $permit->location_name) ?> <bdi dir="ltr">(<?= esc((string) $permit->branch_code) ?>)</bdi></dd>
    <?php if ($permit->to_location_name !== null): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.to_location') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $permit->to_location_name) ?><?= $permit->to_branch_code !== null ? ' <bdi dir="ltr">(' . esc((string) $permit->to_branch_code) . ')</bdi>' : '' ?></dd>
    <?php endif; ?>
    <?php if (trim((string) $permit->supplier_name) !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.supplier') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $permit->supplier_name) ?></dd>
    <?php endif; ?>
    <?php if (trim((string) $permit->customer_name) !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.customer') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $permit->customer_name) ?></dd>
    <?php endif; ?>
    <?php if ($permit->account_name !== null): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.account') ?></dt>
        <dd class="col-sm-9"><?= esc($permit->account_code . ' ' . $permit->account_name) ?></dd>
    <?php endif; ?>
    <?php if ((string) $permit->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $permit->note) ?></dd>
    <?php endif; ?>
</dl>

<div class="table-responsive">
    <table class="table table-sm" id="permit_lines_table">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?= lang('Stock_documents.item') ?></th>
                <th scope="col" class="text-end"><?= lang('Stock_documents.quantity') ?></th>
                <?php if ($withCost): ?>
                    <th scope="col" class="text-end"><?= lang('Stock_documents.unit_cost') ?></th>
                    <th scope="col" class="text-end"><?= lang('Stock_documents.value') ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= (int) $line->line_no ?></td>
                    <td><?= esc((string) $line->item_name) ?></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc(rtrim(rtrim(bcadd((string) $line->quantity, '0', 3), '0'), '.')) ?></bdi></td>
                    <?php if ($withCost): ?>
                        <td class="text-end"><bdi dir="ltr"><?= to_currency($line->unit_cost) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= to_currency(bcmul((string) $line->quantity, (string) $line->unit_cost, 4)) ?></bdi></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($journals !== []): ?>
    <section aria-labelledby="permit_journals_title">
        <h2 id="permit_journals_title" class="h6"><?= lang('Stock_documents.journals') ?></h2>
        <ul class="list-unstyled" id="permit_journals">
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
