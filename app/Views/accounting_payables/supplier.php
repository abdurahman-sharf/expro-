<?php
/**
 * @var string           $supplier_name
 * @var SupplierPosition $position
 * @var string           $today
 * @var list<object>     $applications
 * @var Closure          $amount
 * @var Closure          $date
 */

use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\SupplierPosition;

$aging = $position->aging($today);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.back') ?>
    </a>
</div>

<div class="container-fluid py-3" id="payable_statement">
    <h1 class="h4"><?= esc($supplier_name) ?></h1>

    <dl class="row mb-4">
        <dt class="col-sm-3"><?= lang('Accounting_payables.balance') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->accountBalance) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_payables.open') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->open()) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_payables.credit') ?></dt>
        <dd class="col-sm-9"><?= $amount($position->credit()) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_payables.agrees') ?></dt>
        <dd class="col-sm-9">
            <?php if ($position->agrees()): ?>
                <span class="badge text-bg-success"><?= lang('Accounting_payables.agrees_yes') ?></span>
            <?php else: ?>
                <span class="badge text-bg-warning"><?= lang('Accounting_payables.agrees_no', [to_currency_no_money($position->unclassified())]) ?></span>
            <?php endif; ?>
        </dd>
    </dl>

    <h2 class="h5"><?= lang('Accounting_payables.aging') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_aging">
            <thead>
                <tr>
                    <?php foreach ($aging as $bucket => $unused): ?>
                        <th scope="col"><?= lang('Accounting_payables.age_' . ($bucket === '91+' ? '91_plus' : str_replace('-', '_', $bucket))) ?></th>
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

    <h2 class="h5"><?= lang('Accounting_payables.purchases') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_purchases">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_payables.purchase') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.total') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.paid') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.returned') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.open') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($position->purchases as $purchase): ?>
                    <tr>
                        <td><?= anchor('receivings/receipt/' . $purchase->receivingId, 'RECV ' . $purchase->receivingId, ['target' => '_blank', 'dir' => 'ltr']) ?></td>
                        <td><?= esc($date($purchase->date)) ?></td>
                        <td><?= $amount($purchase->total) ?></td>
                        <td><?= $amount($purchase->paid) ?></td>
                        <td><?= $amount($purchase->applied) ?></td>
                        <td><?= $amount($purchase->returned) ?></td>
                        <td><?= $amount($purchase->open()) ?></td>
                        <td>
                            <?= esc(lang($purchase->paymentState()->languageKey())) ?>
                            <?php if ($purchase->returnState()->languageKey() !== null): ?>
                                <?= esc(lang($purchase->returnState()->languageKey())) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->purchases === []): ?>
                    <tr><td colspan="8"><?= lang('Accounting_payables.no_purchases') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_payables.credits') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_credits">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_payables.source') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.total') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.available') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($position->credits as $source): ?>
                    <tr>
                        <td><?= esc(lang($source->type === CreditSource::DEBIT_NOTE ? 'Accounting_payables.source_debit_note' : 'Accounting_payables.source_excess')) ?> <?= anchor('receivings/receipt/' . $source->sourceId, 'RECV ' . $source->sourceId, ['target' => '_blank', 'dir' => 'ltr']) ?></td>
                        <td><?= esc($date($source->date)) ?></td>
                        <td><?= $amount($source->amount) ?></td>
                        <td><?= $amount($source->applied) ?></td>
                        <td><?= $amount($source->available()) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->credits === []): ?>
                    <tr><td colspan="5"><?= lang('Accounting_payables.no_credits') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_payables.history') ?></h2>
    <div class="table-responsive">
        <table class="table table-sm" id="payable_history">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_payables.source') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.purchase') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.credit_applied') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.applied_at') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.released_at') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $application): ?>
                    <tr>
                        <td><?= esc(lang($application->source_type === CreditSource::DEBIT_NOTE ? 'Accounting_payables.source_debit_note' : 'Accounting_payables.source_excess')) ?> <bdi dir="ltr"><?= (int) $application->source_id ?></bdi></td>
                        <td><bdi dir="ltr"><?= (int) $application->invoice_receiving_id ?></bdi></td>
                        <td><?= $amount($application->amount) ?></td>
                        <td><?= esc($date($application->created_at)) ?></td>
                        <td><?= $application->released_at === null ? '' : esc($date($application->released_at)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($applications === []): ?>
                    <tr><td colspan="5"><?= lang('Accounting_payables.no_history') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
