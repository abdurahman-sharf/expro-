<?php

use App\Libraries\Accounting\AgingBuckets;

/**
 * @var array<string, mixed> $statement
 */
?>
<?= $this->extend('portal/layout') ?>

<?= $this->section('content') ?>
<h1 class="h4 mb-3"><?= esc(lang('Portal.statement_title')) ?></h1>

<?php if (! $statement['available']) : ?>
    <div class="alert alert-info" role="status" id="portal_statement_unavailable"><?= esc(lang('Portal.statement_unavailable')) ?></div>
<?php else : ?>
    <p class="text-secondary"><?= esc(lang('Portal.as_of')) ?> <bdi dir="ltr"><?= esc($statement['as_of']) ?></bdi></p>

    <dl class="row mb-4" id="portal_summary">
        <dt class="col-sm-3"><?= esc(lang('Portal.balance')) ?></dt>
        <dd class="col-sm-9 portal-amount"><bdi dir="ltr"><?= esc($statement['balance']) ?></bdi></dd>
        <dt class="col-sm-3"><?= esc(lang('Portal.open_amount')) ?></dt>
        <dd class="col-sm-9 portal-amount"><bdi dir="ltr"><?= esc($statement['open']) ?></bdi></dd>
        <dt class="col-sm-3"><?= esc(lang('Portal.overdue')) ?></dt>
        <dd class="col-sm-9 portal-amount"><bdi dir="ltr"><?= esc($statement['overdue']) ?></bdi></dd>
        <dt class="col-sm-3"><?= esc(lang('Portal.credit')) ?></dt>
        <dd class="col-sm-9 portal-amount"><bdi dir="ltr"><?= esc($statement['credit']) ?></bdi></dd>
    </dl>

    <h2 class="h5"><?= esc(lang('Portal.aging')) ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered bg-white" id="portal_aging">
            <thead>
                <tr>
                    <?php foreach (AgingBuckets::KEYS as $bucket) : ?>
                        <th scope="col" class="text-end"><?= esc(lang('Accounting_receivables.' . AgingBuckets::LANGUAGE_KEYS[$bucket])) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach (AgingBuckets::KEYS as $bucket) : ?>
                        <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($statement['aging'][$bucket] ?? '') ?></bdi></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <h2 class="h5"><?= esc(lang('Portal.open_invoices')) ?></h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm bg-white" id="portal_open_invoices">
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('Portal.invoice_number')) ?></th>
                    <th scope="col"><?= esc(lang('Portal.date')) ?></th>
                    <th scope="col"><?= esc(lang('Portal.due_date')) ?></th>
                    <th scope="col" class="text-end"><?= esc(lang('Portal.total')) ?></th>
                    <th scope="col" class="text-end"><?= esc(lang('Portal.paid')) ?></th>
                    <th scope="col" class="text-end"><?= esc(lang('Portal.remaining')) ?></th>
                    <th scope="col" class="text-end"><?= esc(lang('Portal.days_overdue')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statement['invoices'] as $invoice) : ?>
                    <tr>
                        <td>
                            <?php if ($invoice['sale_id'] !== null) : ?>
                                <a href="<?= esc(site_url('portal/invoice/' . (int) $invoice['sale_id']), 'attr') ?>"><bdi dir="ltr"><?= esc($invoice['label']) ?></bdi></a>
                            <?php else : ?>
                                <?= esc($invoice['label']) ?>
                            <?php endif; ?>
                        </td>
                        <td><bdi dir="ltr"><?= esc($invoice['date']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($invoice['due_date']) ?></bdi></td>
                        <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($invoice['total']) ?></bdi></td>
                        <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($invoice['settled']) ?></bdi></td>
                        <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($invoice['open']) ?></bdi></td>
                        <td class="text-end"><?= (int) $invoice['days_overdue'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($statement['invoices'] === []) : ?>
                    <tr><td colspan="7" class="text-secondary"><?= esc(lang('Portal.no_rows')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($statement['credits'] !== []) : ?>
        <h2 class="h5"><?= esc(lang('Portal.credit_held')) ?></h2>
        <div class="table-responsive">
            <table class="table table-sm bg-white" id="portal_credits">
                <thead>
                    <tr>
                        <th scope="col"><?= esc(lang('Portal.kind')) ?></th>
                        <th scope="col"><?= esc(lang('Portal.date')) ?></th>
                        <th scope="col" class="text-end"><?= esc(lang('Portal.amount')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statement['credits'] as $credit) : ?>
                        <tr>
                            <td><?= esc($credit['kind']) ?></td>
                            <td><bdi dir="ltr"><?= esc($credit['date']) ?></bdi></td>
                            <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($credit['amount']) ?></bdi></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>
