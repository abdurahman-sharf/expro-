<?php
/**
 * @var string           $customer_name
 * @var CustomerPosition $position
 * @var string           $today
 * @var list<object>     $applications
 * @var list<int>        $refund_invoices
 * @var Closure          $amount
 * @var Closure          $date
 */

use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerPosition;

$aging = $position->aging($today);

$sourceLabel = static fn (string $type, int $sourceId): string => lang(
    $type !== CreditSource::CREDIT_NOTE ? 'Accounting_receivables.source_excess' : (in_array($sourceId, $refund_invoices, true) ? 'Numbering.type_refund_invoice' : 'Accounting_receivables.source_credit_note'),
);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_receivables'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_receivables.back') ?>
    </a>
</div>

<div class="container-fluid py-3" id="receivable_statement">
    <h1 class="h4"><?= esc($customer_name) ?></h1>

    <dl class="row mb-4">
        <dt class="col-sm-3"><?= lang('Accounting_receivables.balance') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->accountBalance) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_receivables.open') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->open()) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_receivables.credit') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->credit()) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_receivables.agrees') ?></dt>
        <dd class="col-sm-9">
            <?php if ($position->agrees()): ?>
                <span class="badge text-bg-success"><?= lang('Accounting_receivables.agrees_yes') ?></span>
            <?php else: ?>
                <span class="badge text-bg-warning"><?= lang('Accounting_receivables.agrees_no', [to_currency_no_money($position->unclassified())]) ?></span>
            <?php endif; ?>
        </dd>
    </dl>

    <h2 class="h5"><?= lang('Accounting_receivables.aging') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="receivable_aging">
            <thead>
                <tr>
                    <?php foreach ($aging as $bucket => $unused): ?>
                        <th scope="col"><?= lang('Accounting_receivables.age_' . ($bucket === '91+' ? '91_plus' : str_replace('-', '_', $bucket))) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($aging as $value): ?>
                        <td><?= $amount($value) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_receivables.invoices') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="receivable_invoices">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_receivables.invoice') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.total') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.paid') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.returned') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.open') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($position->invoices as $invoice): ?>
                    <tr>
                        <td><?= anchor('sales/receipt/' . $invoice->saleId, 'POS ' . $invoice->saleId, ['target' => '_blank', 'dir' => 'ltr']) ?></td>
                        <td><?= esc($date($invoice->date)) ?></td>
                        <td><?= $amount($invoice->total) ?></td>
                        <td><?= $amount($invoice->paid) ?></td>
                        <td><?= $amount($invoice->applied) ?></td>
                        <td><?= $amount($invoice->returned) ?></td>
                        <td><?= $amount($invoice->open()) ?></td>
                        <td>
                            <?= esc(lang($invoice->paymentState()->languageKey())) ?>
                            <?php if ($invoice->returnState()->languageKey() !== null): ?>
                                <?= esc(lang($invoice->returnState()->languageKey())) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->invoices === []): ?>
                    <tr><td colspan="8"><?= lang('Accounting_receivables.no_invoices') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_receivables.credits') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="receivable_credits">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_receivables.source') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.total') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.available') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($position->credits as $source): ?>
                    <tr>
                        <td><?= esc($sourceLabel($source->type, $source->sourceId)) ?> <?= anchor('sales/receipt/' . $source->sourceId, 'POS ' . $source->sourceId, ['target' => '_blank', 'dir' => 'ltr']) ?></td>
                        <td><?= esc($date($source->date)) ?></td>
                        <td><?= $amount($source->amount) ?></td>
                        <td><?= $amount($source->applied) ?></td>
                        <td><?= $amount($source->available()) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->credits === []): ?>
                    <tr><td colspan="5"><?= lang('Accounting_receivables.no_credits') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_receivables.history') ?></h2>
    <div class="table-responsive">
        <table class="table table-sm" id="receivable_history">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_receivables.source') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.invoice') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.applied_at') ?></th>
                    <th scope="col"><?= lang('Accounting_receivables.released_at') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $application): ?>
                    <tr>
                        <td><?= esc($sourceLabel($application->source_type, (int) $application->source_id)) ?> <bdi dir="ltr"><?= (int) $application->source_id ?></bdi></td>
                        <td><bdi dir="ltr"><?= (int) $application->invoice_sale_id ?></bdi></td>
                        <td><?= $amount($application->amount) ?></td>
                        <td><?= esc($date($application->created_at)) ?></td>
                        <td><?= $application->released_at === null ? '' : esc($date($application->released_at)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($applications === []): ?>
                    <tr><td colspan="5"><?= lang('Accounting_receivables.no_history') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
