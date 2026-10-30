<?php
/**
 * @var string           $customer_name
 * @var CustomerPosition $position
 * @var string           $today
 * @var string           $to
 * @var string|null      $error
 * @var list<object>     $applications
 * @var Closure          $amount
 * @var Closure          $date
 */

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerPosition;

$aging = $position->aging($today);
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
        <dt class="col-sm-3"><?= lang('Accounting_receivables.overdue') ?></dt>
        <dd class="col-sm-9" id="statement_overdue"><?= $amount($position->overdue($today)) ?></dd>
    </dl>

    <form id="statement_day" class="print_hide mb-3 d-flex flex-wrap align-items-end gap-2" method="get" role="search">
        <div>
            <label class="form-label" for="f_to"><?= lang('Accounting_receivables.aged_to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($to, 'attr') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
    </form>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_receivables.aging') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="receivable_aging">
            <thead>
                <tr>
                    <?php foreach ($aging as $bucket => $unused): ?>
                        <th scope="col"><?= lang('Accounting_receivables.' . AgingBuckets::LANGUAGE_KEYS[$bucket]) ?></th>
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
                    <th scope="col"><?= lang('Accounting_receivables.due_date') ?></th>
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
                        <td><?= esc($date($invoice->dueDate)) ?></td>
                        <td><?= $amount($invoice->total) ?></td>
                        <td><?= $amount($invoice->paid) ?></td>
                        <td><?= $amount($invoice->applied) ?></td>
                        <td><?= $amount($invoice->returned) ?></td>
                        <td><?= $amount($invoice->open()) ?></td>
                        <td>
                            <?= esc(lang($invoice->paymentState()->languageKey())) ?>
                            <?php if ($invoice->isOverdue($today)): ?>
                                <span class="badge text-bg-danger"><?= esc(lang('Accounting_receivables.overdue_days', [$invoice->daysOverdue($today)])) ?></span>
                            <?php endif; ?>
                            <?php if ($invoice->returnState()->languageKey() !== null): ?>
                                <?= esc(lang($invoice->returnState()->languageKey())) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->invoices === []): ?>
                    <tr><td colspan="9"><?= lang('Accounting_receivables.no_invoices') ?></td></tr>
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
                        <td><?= esc(lang($source->type === CreditSource::CREDIT_NOTE ? 'Accounting_receivables.source_credit_note' : 'Accounting_receivables.source_excess')) ?> <?= anchor('sales/receipt/' . $source->sourceId, 'POS ' . $source->sourceId, ['target' => '_blank', 'dir' => 'ltr']) ?></td>
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
                        <td><?= esc(lang($application->source_type === CreditSource::CREDIT_NOTE ? 'Accounting_receivables.source_credit_note' : 'Accounting_receivables.source_excess')) ?> <bdi dir="ltr"><?= (int) $application->source_id ?></bdi></td>
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
