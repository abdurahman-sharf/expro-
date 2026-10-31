<?php
/**
 * @var string            $supplier_name
 * @var SupplierPosition  $position
 * @var string            $today
 * @var string            $to
 * @var string|null       $error
 * @var list<object>      $applications
 * @var int               $supplier_id
 * @var list<object>      $advances
 * @var array<int,string> $advance_kinds
 * @var list<object>      $refunds
 * @var bool              $can_manage
 * @var bool              $can_refund
 * @var Closure           $amount
 * @var Closure           $date
 */

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\SupplierPosition;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;

$aging        = $position->aging($today);
$chequeNumber = static fn (int $chequeId): string => (new DocumentNumbering())->label(DocumentType::PaidCheque, 'cheques', $chequeId);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.back') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables/payments?supplier_id=' . $supplier_id), 'attr') ?>">
        <span class="bi bi-cash-coin" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.payments') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables/debit_notes?supplier_id=' . $supplier_id), 'attr') ?>">
        <span class="bi bi-file-earmark-minus" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.debit_notes') ?>
    </a>
    <?php if ($can_refund): ?>
        <button class="btn btn-outline-secondary btn-sm modal-dlg" id="supplier_refund_button" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_payables/refund_form/<?= $supplier_id ?>" title="<?= lang('Accounting_payables.refund_new') ?>">
            <span class="bi bi-arrow-return-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.refund_new') ?>
        </button>
    <?php endif; ?>
    <?php if ($can_manage): ?>
        <button class="btn btn-outline-secondary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_payables/opening_form/<?= $supplier_id ?>" title="<?= lang('Accounting_payables.opening_new') ?>">
            <span class="bi bi-flag" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.opening_new') ?>
        </button>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_payables/payment_form/<?= $supplier_id ?>" title="<?= lang('Accounting_payables.payment_new') ?>">
            <span class="bi bi-cash-coin" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.payment_new') ?>
        </button>
    <?php endif; ?>
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
        <dt class="col-sm-3"><?= lang('Accounting_payables.overdue') ?></dt>
        <dd class="col-sm-9" id="statement_overdue"><?= $amount($position->overdue($today)) ?></dd>
    </dl>

    <form id="statement_day" class="print_hide mb-3 d-flex flex-wrap align-items-end gap-2" method="get" role="search">
        <div>
            <label class="form-label" for="f_to"><?= lang('Accounting_payables.aged_to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($to, 'attr') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
    </form>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_payables.aging') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_aging">
            <thead>
                <tr>
                    <?php foreach ($aging as $bucket => $unused): ?>
                        <th scope="col"><?= lang('Accounting_payables.' . AgingBuckets::LANGUAGE_KEYS[$bucket]) ?></th>
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
                    <th scope="col"><?= lang('Accounting_payables.due_date') ?></th>
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
                        <td>
                            <?php if ($purchase->opening): ?>
                                <?= lang('Accounting_payables.opening_balance') ?>
                                <?php if ($can_manage): ?>
                                    <?= anchor(
                                        'accounting_payables/cancel_form/opening/' . $purchase->receivingId,
                                        '<span class="bi bi-x-circle" aria-hidden="true"></span>',
                                        ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_payables.cancel'), 'aria-label' => lang('Accounting_payables.cancel')],
                                    ) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= anchor('receivings/receipt/' . $purchase->receivingId, 'RECV ' . $purchase->receivingId, ['target' => '_blank', 'dir' => 'ltr']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($date($purchase->date)) ?></td>
                        <td><?= esc($date($purchase->dueDate)) ?></td>
                        <td><?= $amount($purchase->total) ?></td>
                        <td><?= $amount($purchase->paid) ?></td>
                        <td><?= $amount($purchase->applied) ?></td>
                        <td><?= $amount($purchase->returned) ?></td>
                        <td><?= $amount($purchase->open()) ?></td>
                        <td>
                            <?= esc(lang($purchase->paymentState()->languageKey())) ?>
                            <?php if ($purchase->isOverdue($today)): ?>
                                <span class="badge text-bg-danger"><?= esc(lang('Accounting_payables.overdue_days', [$purchase->daysOverdue($today)])) ?></span>
                            <?php endif; ?>
                            <?php if ($purchase->returnState()->languageKey() !== null): ?>
                                <?= esc(lang($purchase->returnState()->languageKey())) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->purchases === []): ?>
                    <tr><td colspan="9"><?= lang('Accounting_payables.no_purchases') ?></td></tr>
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
                    <th scope="col"><?= lang('Accounting_payables.refunded') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.available') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($position->credits as $source): ?>
                    <tr>
                        <td>
                            <?php if ($source->type === CreditSource::ADVANCE): ?>
                                <?= esc(lang(($advance_kinds[$source->sourceId] ?? 'payment') === 'opening' ? 'Accounting_payables.source_advance_opening' : 'Accounting_payables.source_advance')) ?> <bdi dir="ltr">#<?= $source->sourceId ?></bdi>
                            <?php elseif ($source->type === CreditSource::CHEQUE): ?>
                                <?= esc(lang('Accounting_cheques.credit_source')) ?> <?= anchor('accounting_cheques/view/' . $source->sourceId, esc($chequeNumber($source->sourceId)), ['target' => '_blank', 'dir' => 'ltr']) ?>
                            <?php else: ?>
                                <?= esc(lang($source->type === CreditSource::DEBIT_NOTE ? 'Accounting_payables.source_debit_note' : 'Accounting_payables.source_excess')) ?> <?= anchor('receivings/receipt/' . $source->sourceId, 'RECV ' . $source->sourceId, ['target' => '_blank', 'dir' => 'ltr']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($date($source->date)) ?></td>
                        <td><?= $amount($source->amount) ?></td>
                        <td><?= $amount($source->applied) ?></td>
                        <td><?= $amount($source->refunded) ?></td>
                        <td><?= $amount($source->available()) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($position->credits === []): ?>
                    <tr><td colspan="6"><?= lang('Accounting_payables.no_credits') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_payables.advances') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_advances">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_payables.advance_number') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.kind') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.amount') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.treasury') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.reference') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.note_label') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.status') ?></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($advances as $advance): ?>
                    <tr>
                        <td><bdi dir="ltr">#<?= (int) $advance->advance_id ?></bdi></td>
                        <td><?= esc($date((string) $advance->advance_date)) ?></td>
                        <td><?= esc(lang($advance->kind === 'opening' ? 'Accounting_payables.source_advance_opening' : 'Accounting_payables.source_advance')) ?></td>
                        <td><?= $amount((string) $advance->amount) ?></td>
                        <td><?= esc((string) ($advance->treasury_name ?? '')) ?></td>
                        <td><?= esc((string) $advance->reference_code) ?></td>
                        <td><?= esc((string) $advance->note) ?></td>
                        <td><span class="badge <?= $advance->status === 'cancelled' ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= esc(lang('Accounting_payables.status_' . $advance->status)) ?></span></td>
                        <td>
                            <?php if ($can_manage && $advance->status === 'completed'): ?>
                                <?= anchor(
                                    'accounting_payables/cancel_form/supplier_advance/' . (int) $advance->advance_id,
                                    '<span class="bi bi-x-circle" aria-hidden="true"></span>',
                                    ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_payables.cancel'), 'aria-label' => lang('Accounting_payables.cancel')],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($advances === []): ?>
                    <tr><td colspan="9"><?= lang('Accounting_payables.no_advances') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= lang('Accounting_payables.refunds') ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm" id="payable_refunds">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_payables.advance_number') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.date') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.amount') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.treasury') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.reference') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.note_label') ?></th>
                    <th scope="col"><?= lang('Accounting_payables.status') ?></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($refunds as $refund): ?>
                    <tr>
                        <td><bdi dir="ltr">#<?= (int) $refund->refund_id ?></bdi></td>
                        <td><?= esc($date((string) $refund->refund_date)) ?></td>
                        <td><?= $amount((string) $refund->amount) ?></td>
                        <td><?= esc((string) ($refund->treasury_name ?? '')) ?></td>
                        <td><?= esc((string) $refund->reference_code) ?></td>
                        <td><?= esc((string) $refund->note) ?></td>
                        <td><span class="badge <?= $refund->status === 'cancelled' ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= esc(lang('Accounting_payables.status_' . $refund->status)) ?></span></td>
                        <td>
                            <?php if ($can_refund && $refund->status === 'completed'): ?>
                                <?= anchor(
                                    'accounting_payables/cancel_form/supplier_refund/' . (int) $refund->refund_id,
                                    '<span class="bi bi-x-circle" aria-hidden="true"></span>',
                                    ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_payables.cancel'), 'aria-label' => lang('Accounting_payables.cancel')],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($refunds === []): ?>
                    <tr><td colspan="8"><?= lang('Accounting_payables.no_refunds') ?></td></tr>
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
                        <td><?= esc(lang(match ($application->source_type) {
                            CreditSource::DEBIT_NOTE => 'Accounting_payables.source_debit_note',
                            CreditSource::ADVANCE    => 'Accounting_payables.source_advance',
                            CreditSource::CHEQUE     => 'Accounting_cheques.credit_source',
                            default                  => 'Accounting_payables.source_excess',
                        })) ?> <bdi dir="ltr"><?= $application->source_type === CreditSource::CHEQUE ? esc($chequeNumber((int) $application->source_id)) : (int) $application->source_id ?></bdi></td>
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
