<?php

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;

/**
 * @var PurchaseDocumentKind         $kind
 * @var object                       $document
 * @var PurchaseDocumentStatus       $status
 * @var string                       $number
 * @var list<object>                 $lines
 * @var list<object>                 $madeFrom
 * @var object|null                  $madeOf
 * @var list<object>                 $receivings
 * @var bool                         $canEdit
 * @var bool                         $canStatus
 * @var bool                         $canCancel
 * @var bool                         $canConvert
 * @var list<PurchaseDocumentStatus> $next
 * @var PurchaseDocumentKind|null    $nextKind
 */
$documentId = (int) $document->document_id;
$module     = $kind->module();
$isOrder    = $kind === PurchaseDocumentKind::Order;
$total      = array_reduce($lines, static fn (string $sum, object $line): string => bcadd($sum, bcmul((string) $line->quantity, (string) $line->unit_price, 5), 5), '0');
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar gap-2">
    <h1 class="h5 me-auto mb-0"><?= lang($kind->labelKey()) ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module), 'attr') ?>"><?= lang('Purchase_cycle.back') ?></a>
    <?php if ($canEdit): ?>
        <a class="btn btn-outline-secondary btn-sm" id="document_edit" href="<?= esc(base_url($module . '/edit/' . $documentId), 'attr') ?>"><?= lang('Purchase_cycle.edit') ?></a>
    <?php endif; ?>
    <?php if ($canStatus): ?>
        <?php foreach ($next as $to): ?>
            <button type="button" class="btn btn-outline-primary btn-sm document-status" data-status="<?= esc($to->value, 'attr') ?>"><?= lang('Purchase_cycle.move_to_' . $to->value) ?></button>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($canConvert): ?>
        <button type="button" class="btn btn-primary btn-sm" id="document_convert"><?= lang($isOrder ? 'Purchase_cycle.convert_receive' : 'Purchase_cycle.convert_to_' . $nextKind->value) ?></button>
    <?php endif; ?>
    <?php if ($canCancel): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" id="document_cancel"><?= lang('Purchase_cycle.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="document_header">
    <dt class="col-sm-3"><?= lang('Purchase_cycle.status') ?></dt>
    <dd class="col-sm-9"><span class="badge text-bg-primary" id="document_status"><?= lang($status->languageKey()) ?></span></dd>
    <?php if ($kind->hasSupplier()): ?>
        <dt class="col-sm-3"><?= lang('Purchase_cycle.supplier') ?></dt>
        <dd class="col-sm-9"><?= esc((string) ($document->supplier_name ?? '')) ?></dd>
    <?php endif; ?>
    <dt class="col-sm-3"><?= lang('Purchase_cycle.date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $document->document_date) ?></bdi></dd>
    <?php if (($document->due_date ?? null) !== null): ?>
        <dt class="col-sm-3"><?= lang($kind->dueLabelKey()) ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $document->due_date) ?></bdi></dd>
    <?php endif; ?>
    <dt class="col-sm-3"><?= lang('Purchase_cycle.location') ?></dt>
    <dd class="col-sm-9"><?= esc((string) ($document->location_name ?? '')) ?></dd>
    <dt class="col-sm-3"><?= lang('Purchase_cycle.branch') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $document->branch_code) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Purchase_cycle.created_by') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $document->creator_name) ?></dd>
    <?php if ($madeOf !== null): ?>
        <dt class="col-sm-3"><?= lang('Purchase_cycle.made_of') ?></dt>
        <dd class="col-sm-9" id="document_made_of"><a href="<?= esc(base_url($madeOf->kind->module() . '/view/' . $madeOf->document_id), 'attr') ?>"><?= lang($madeOf->kind->labelKey()) ?> <bdi dir="ltr"><?= esc($madeOf->number) ?></bdi></a></dd>
    <?php endif; ?>
    <?php if ((string) $document->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Purchase_cycle.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $document->note) ?></dd>
    <?php endif; ?>
    <?php if ($document->status === 'cancelled'): ?>
        <dt class="col-sm-3"><?= lang('Purchase_cycle.cancel_reason') ?></dt>
        <dd class="col-sm-9" id="document_cancel_reason"><?= esc((string) $document->cancel_reason) ?></dd>
    <?php endif; ?>
</dl>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="document_lines">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?= lang('Purchase_cycle.item') ?></th>
                <th scope="col" class="text-end"><?= lang('Purchase_cycle.quantity') ?></th>
                <?php if ($isOrder): ?>
                    <th scope="col" class="text-end"><?= lang('Purchase_cycle.received') ?></th>
                    <th scope="col" class="text-end"><?= lang('Purchase_cycle.remaining') ?></th>
                <?php endif; ?>
                <th scope="col" class="text-end"><?= lang('Purchase_cycle.unit_price') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr id="document_line_<?= (int) $line->line_id ?>">
                    <td><?= (int) $line->line_no ?></td>
                    <td><?= esc((string) $line->item_name) ?><?= (string) $line->description === '' ? '' : '<br><small class="text-body-secondary">' . esc((string) $line->description) . '</small>' ?></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->quantity) ?></bdi></td>
                    <?php if ($isOrder): ?>
                        <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->received) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->remaining) ?></bdi></td>
                    <?php endif; ?>
                    <td class="text-end"><bdi dir="ltr"><?= to_currency($line->unit_price) ?></bdi></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="<?= $isOrder ? 5 : 3 ?>" class="text-end"><?= lang('Purchase_cycle.gross') ?></th>
                <td class="text-end" id="document_total"><bdi dir="ltr"><?= to_currency($total) ?></bdi></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php if ($nextKind !== null): ?>
    <h2 class="h6 mt-3"><?= lang('Purchase_cycle.made_documents') ?></h2>
    <ul id="document_made_from">
        <?php if ($madeFrom === []): ?>
            <li class="text-body-secondary"><?= lang('Purchase_cycle.no_made_documents') ?></li>
        <?php endif; ?>
        <?php foreach ($madeFrom as $made): ?>
            <li id="document_made_<?= (int) $made->document_id ?>">
                <a href="<?= esc(base_url($nextKind->module() . '/view/' . (int) $made->document_id), 'attr') ?>"><?= lang($nextKind->labelKey()) ?> <bdi dir="ltr"><?= esc((string) $made->number) ?></bdi></a>
                <span class="badge text-bg-<?= $made->status === 'cancelled' ? 'secondary' : 'primary' ?>"><?= lang('Purchase_cycle.status_' . $made->status) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($isOrder): ?>
    <h2 class="h6 mt-3"><?= lang('Purchase_cycle.receivings') ?></h2>
    <ul id="document_receivings">
        <?php if ($receivings === []): ?>
            <li class="text-body-secondary"><?= lang('Purchase_cycle.no_receivings') ?></li>
        <?php endif; ?>
        <?php foreach ($receivings as $receiving): ?>
            <li id="document_receiving_<?= (int) $receiving->receiving_id ?>">
                <a href="<?= esc(base_url('receivings/receipt/' . (int) $receiving->receiving_id), 'attr') ?>"><bdi dir="ltr">RECV <?= (int) $receiving->receiving_id ?></bdi></a>
                <bdi dir="ltr"><?= esc((string) $receiving->units) ?></bdi>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<script type="text/javascript">
    $(document).ready(function() {
        const post = function(url, data) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        window.location.reload();
                    }
                }
            }, 'json').fail(function() {
                toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
            });
        };

        $('.document-status').on('click', function() {
            post('<?= esc($module, 'js') ?>/status/<?= $documentId ?>', {status: $(this).data('status')});
        });

        $('#document_convert').on('click', function() {
            post('<?= esc($module, 'js') ?>/convert/<?= $documentId ?>', {});
        });

        $('#document_cancel').on('click', function() {
            const reason = window.prompt("<?= esc(lang('Purchase_cycle.cancel_prompt'), 'js') ?>");

            if (reason !== null) {
                post('<?= esc($module, 'js') ?>/cancel/<?= $documentId ?>', {reason: reason});
            }
        });
    });
</script>

<?= view('partial/footer') ?>
