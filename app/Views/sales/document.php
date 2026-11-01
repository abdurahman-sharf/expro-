<?php
/**
 * @var string            $kind
 * @var object            $header
 * @var string            $number
 * @var list<object>      $lines
 * @var array|null        $figures
 * @var QuoteStatus|null  $quote_status
 * @var list<QuoteStatus> $next_statuses
 * @var int|null          $invoice_id
 * @var string|null       $invoice_number
 * @var bool              $accounting_on
 * @var bool              $can_type_date
 * @var bool              $can_delete
 * @var bool              $invoice_enabled
 * @var string            $today
 */

use App\Libraries\Accounting\QuoteStatus;

$saleId  = (int) $header->sale_id;
$isDraft = $kind === 'draft';
$locked  = $quote_status?->isLocked() === true;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" id="document_back_link" href="<?= esc(site_url('sales/documents'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Sales.document_back') ?>
    </a>
</div>

<div class="container-fluid py-3" id="sale_document" data-kind="<?= esc($kind, 'attr') ?>">
    <h1 class="h4">
        <?= lang($isDraft ? 'Sales.draft_invoice' : 'Sales.quote') ?>
        <bdi dir="ltr" id="document_number"><?= esc($number) ?></bdi>
        <?php if ($quote_status !== null): ?>
            <span class="badge text-bg-primary" id="quote_status_badge"><?= esc(lang($quote_status->languageKey())) ?></span>
        <?php endif; ?>
    </h1>

    <dl class="row mb-4">
        <dt class="col-sm-3"><?= lang('Sales.customer') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $header->customer_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Sales.employee') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $header->employee_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Sales.date') ?></dt>
        <dd class="col-sm-9"><?= esc(to_date(strtotime((string) $header->sale_time))) ?></dd>
        <dt class="col-sm-3"><?= lang('Sales.document_branch') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $header->branch_code) ?></dd>
        <?php if ($isDraft && $header->invoice_date !== null): ?>
            <dt class="col-sm-3"><?= lang('Sales.invoice_date') ?></dt>
            <dd class="col-sm-9" id="draft_invoice_date"><?= esc(to_date(strtotime((string) $header->invoice_date))) ?></dd>
        <?php endif; ?>
        <?php if ($isDraft && $header->payment_terms_days !== null): ?>
            <dt class="col-sm-3"><?= lang('Sales.payment_terms') ?></dt>
            <dd class="col-sm-9" id="draft_payment_terms"><?= (int) $header->payment_terms_days ?> <?= lang('Sales.payment_terms_days') ?></dd>
        <?php endif; ?>
        <?php if (trim((string) $header->comment) !== ''): ?>
            <dt class="col-sm-3"><?= lang('Sales.comments') ?></dt>
            <dd class="col-sm-9"><?= esc((string) $header->comment) ?></dd>
        <?php endif; ?>
        <?php if ($invoice_id !== null): ?>
            <dt class="col-sm-3"><?= lang('Sales.invoice') ?></dt>
            <dd class="col-sm-9" id="converted_invoice">
                <a href="<?= esc(site_url('sales/invoice/' . $invoice_id), 'attr') ?>"><?= lang('Sales.quote_converted_to', [esc((string) ($invoice_number ?? '#' . $invoice_id))]) ?></a>
            </dd>
        <?php endif; ?>
    </dl>

    <div class="table-responsive mb-4">
        <table class="table table-sm" id="document_lines">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Sales.item_name') ?></th>
                    <th scope="col"><?= lang('Sales.quantity') ?></th>
                    <th scope="col"><?= lang('Sales.price') ?></th>
                    <th scope="col"><?= lang('Sales.discount') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?= esc((string) $line->name) ?><?= trim((string) $line->description) !== '' ? ' <small class="text-body-secondary">' . esc((string) $line->description) . '</small>' : '' ?></td>
                        <td><bdi dir="ltr"><?= esc(to_quantity_decimals((string) $line->quantity_purchased)) ?></bdi></td>
                        <td><bdi dir="ltr"><?= to_currency($line->item_unit_price) ?></bdi></td>
                        <td><bdi dir="ltr"><?= (int) $line->discount_type === PERCENT ? esc(to_decimals((string) $line->discount)) . '%' : to_currency($line->discount) ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($figures !== null): ?>
        <table class="table table-sm w-auto mb-2" id="document_figures">
            <tbody>
                <tr><th scope="row"><?= lang('Sales.sub_total') ?></th><td><bdi dir="ltr"><?= to_currency(bcsub(bcsub($figures['gross'], $figures['discount'], 4), $figures['invoiceDiscount'], 4)) ?></bdi></td></tr>
                <?php if (bccomp($figures['invoiceDiscount'], '0', 4) > 0): ?>
                    <tr><th scope="row"><?= lang('Sales.invoice_discount') ?></th><td><bdi dir="ltr"><?= to_currency($figures['invoiceDiscount']) ?></bdi></td></tr>
                <?php endif; ?>
                <tr><th scope="row"><?= lang('Sales.tax') ?></th><td><bdi dir="ltr"><?= to_currency($figures['tax']) ?></bdi></td></tr>
                <?php foreach (headerChargeRows(['shipping' => $figures['shipping'], 'shipping_tax' => $figures['shippingTax'], 'shipping_tax_included' => $figures['shippingTaxIncluded'], 'adjustment' => $figures['adjustment'], 'adjustment_label' => $figures['adjustmentLabel']]) as [$chargeLabel, $chargeAmount]): ?>
                    <tr><th scope="row"><?= esc($chargeLabel) ?></th><td><bdi dir="ltr"><?= to_currency($chargeAmount) ?></bdi></td></tr>
                <?php endforeach; ?>
                <tr><th scope="row"><?= lang('Sales.total') ?></th><td><bdi dir="ltr"><?= to_currency($figures['total']) ?></bdi></td></tr>
            </tbody>
        </table>
        <p class="small text-body-secondary mb-4"><?= lang('Sales.document_figures_note') ?></p>
    <?php endif; ?>

    <div class="print_hide d-flex flex-wrap gap-2 align-items-end" id="document_actions">
        <?php if ($isDraft): ?>
            <form method="post" id="issue_form" action="<?= esc(site_url('sales/issue/' . $saleId), 'attr') ?>" class="d-flex flex-wrap align-items-end gap-2">
                <?= csrf_field() ?>
                <?php if ($can_type_date): ?>
                    <div>
                        <label class="form-label" for="issue_invoice_date"><?= lang('Sales.invoice_date') ?></label>
                        <input type="date" class="form-control form-control-sm" id="issue_invoice_date" name="invoice_date" max="<?= esc($today, 'attr') ?>" value="<?= esc((string) ($header->invoice_date ?? ''), 'attr') ?>">
                    </div>
                <?php endif; ?>
                <?php if ($accounting_on): ?>
                    <div>
                        <label class="form-label" for="issue_payment_terms"><?= lang('Sales.payment_terms') ?> (<?= lang('Sales.payment_terms_days') ?>)</label>
                        <input type="number" min="0" class="form-control form-control-sm" id="issue_payment_terms" name="payment_terms" value="<?= esc((string) ($header->payment_terms_days ?? ''), 'attr') ?>">
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-success" id="issue_button"><span class="bi bi-check-lg" aria-hidden="true">&nbsp;</span><?= lang('Sales.draft_issue') ?></button>
            </form>
        <?php elseif ($quote_status !== null && $next_statuses !== []): ?>
            <form method="post" id="quote_status_form" action="<?= esc(site_url('sales/quoteStatus/' . $saleId), 'attr') ?>" class="d-flex flex-wrap align-items-end gap-2">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label" for="quote_status_select"><?= lang('Sales.quote_status_label') ?></label>
                    <select id="quote_status_select" name="quote_status" class="form-select form-select-sm">
                        <?php foreach ($next_statuses as $status): ?>
                            <option value="<?= $status->value ?>"><?= esc(lang($status->languageKey())) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" id="quote_status_button"><?= lang('Sales.quote_status_change') ?></button>
            </form>
        <?php endif; ?>

        <?php if ($quote_status !== null && $quote_status->canConvert() && $invoice_enabled): ?>
            <form method="post" id="convert_form" action="<?= esc(site_url('sales/convertQuote/' . $saleId), 'attr') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success" id="convert_button" data-confirm="<?= $quote_status->isAccepted() ? '' : esc(lang('Sales.quote_convert_confirm'), 'attr') ?>"><span class="bi bi-receipt" aria-hidden="true">&nbsp;</span><?= lang('Sales.quote_convert') ?></button>
            </form>
        <?php endif; ?>

        <?php if (! $locked): ?>
            <form method="post" action="<?= esc(site_url('sales/unsuspend'), 'attr') ?>" id="register_form">
                <?= csrf_field() ?>
                <input type="hidden" name="suspended_sale_id" value="<?= $saleId ?>">
                <button type="submit" class="btn btn-outline-primary" id="register_button"><span class="bi bi-cart3" aria-hidden="true">&nbsp;</span><?= lang('Sales.document_edit_register') ?></button>
            </form>
        <?php endif; ?>

        <?php if (! $isDraft): ?>
            <a class="btn btn-outline-secondary" id="document_pdf" href="<?= esc(site_url('sales/pdf/' . $saleId . '/quote'), 'attr') ?>" target="_blank" rel="noopener"><span class="bi bi-file-earmark-pdf" aria-hidden="true">&nbsp;</span><?= lang('Sales.pdf') ?></a>
        <?php endif; ?>

        <?php if ($can_delete && ! $locked): ?>
            <button type="button" class="btn btn-outline-danger" id="delete_button" data-confirm="<?= esc(lang($isDraft ? 'Sales.draft_delete_confirm' : 'Sales.confirm_cancel_sale'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang($isDraft ? 'Sales.draft_delete' : 'Sales.discard_quote') ?></button>
        <?php endif; ?>
    </div>

    <?php if ($quote_status !== null && $next_statuses !== []): ?>
        <p class="small text-body-secondary mt-3 mb-0"><?= lang('Sales.quote_status_manual_note') ?></p>
    <?php endif; ?>
    <?php if ($locked): ?>
        <p class="small text-body-secondary mt-3 mb-0" id="quote_locked_note"><?= lang('Sales.quote_locked_note') ?></p>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const buttons = $('#document_actions button');
        const failure = <?= json_encode(lang('Sales.document_request_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const refused = function(message) {
            $.notify({message: message}, {type: 'danger'});
            buttons.prop('disabled', false);
        };

        const post = function(url, data, succeeded) {
            buttons.prop('disabled', true);

            $.post(url, data, function(response) {
                if (response.success) {
                    succeeded(response);
                } else {
                    refused(response.message);
                }
            }, 'json').fail(function(request) {
                refused(request.responseJSON && request.responseJSON.message ? request.responseJSON.message : failure);
            });
        };

        const answer = function(response) {
            if (response.redirect) {
                window.location.href = response.redirect;
            } else {
                window.location.reload();
            }
        };

        const send = function(form, confirmation) {
            if (confirmation && !window.confirm(confirmation)) {
                return;
            }

            post($(form).attr('action'), $(form).serialize(), answer);
        };

        $('#issue_form').on('submit', function(event) {
            event.preventDefault();
            send(this, <?= json_encode(lang('Sales.draft_issue_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        });

        $('#quote_status_form').on('submit', function(event) {
            event.preventDefault();
            send(this, '');
        });

        $('#convert_form').on('submit', function(event) {
            event.preventDefault();
            send(this, $('#convert_button').data('confirm'));
        });

        $('#delete_button').on('click', function() {
            if (window.confirm($(this).data('confirm'))) {
                post(<?= json_encode(site_url('sales/delete/' . $saleId), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {}, function() {
                    window.location.href = <?= json_encode(site_url('sales/documents'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                });
            }
        });
    });
</script>

<?= view('partial/footer') ?>
