<?php
/**
 * @var list<object>      $drafts
 * @var list<object>      $quotes
 * @var QuoteStatus|null  $quote_filter
 * @var list<QuoteStatus> $statuses
 * @var bool              $show_branch
 * @var array             $config
 */

use App\Libraries\Accounting\QuoteStatus;

$badges = [
    QuoteStatus::Draft->value     => 'text-bg-secondary',
    QuoteStatus::Open->value      => 'text-bg-primary',
    QuoteStatus::Sent->value      => 'text-bg-info',
    QuoteStatus::Viewed->value    => 'text-bg-info',
    QuoteStatus::Replied->value   => 'text-bg-info',
    QuoteStatus::Approved->value  => 'text-bg-success',
    QuoteStatus::Converted->value => 'text-bg-dark',
    QuoteStatus::Ordered->value   => 'text-bg-success',
];
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" id="documents_register_link" href="<?= esc(site_url('sales'), 'attr') ?>">
        <span class="bi bi-cart3" aria-hidden="true">&nbsp;</span><?= lang('Sales.register') ?>
    </a>
    <p class="mb-0 text-body-secondary"><?= lang('Sales.documents_note') ?></p>
</div>

<div class="container-fluid py-3" id="sale_documents">
    <h1 class="h4"><?= lang('Sales.documents_title') ?></h1>

    <h2 class="h5 mt-4"><?= lang('Sales.documents_drafts') ?></h2>
    <?php if ($drafts === []): ?>
        <p class="text-body-secondary" id="drafts_empty"><?= lang('Sales.documents_drafts_empty') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="drafts_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Sales.draft_temporary_number') ?></th>
                        <th scope="col"><?= lang('Sales.date') ?></th>
                        <th scope="col"><?= lang('Sales.customer') ?></th>
                        <th scope="col"><?= lang('Sales.employee') ?></th>
                        <?php if ($show_branch): ?>
                            <th scope="col" class="document-branch"><?= lang('Sales.document_branch') ?></th>
                        <?php endif; ?>
                        <th scope="col"><?= lang('Sales.sub_total') ?></th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drafts as $draft): ?>
                        <tr data-sale-id="<?= (int) $draft->sale_id ?>">
                            <td><bdi dir="ltr"><?= esc($draft->label) ?></bdi></td>
                            <td><?= esc(to_date(strtotime((string) $draft->sale_time))) ?></td>
                            <td><?= esc((string) $draft->customer_name) ?></td>
                            <td><?= esc((string) $draft->employee_name) ?></td>
                            <?php if ($show_branch): ?>
                                <td class="document-branch"><?= esc((string) $draft->branch_code) ?></td>
                            <?php endif; ?>
                            <td><bdi dir="ltr"><?= to_currency($draft->subtotal) ?></bdi></td>
                            <td><a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('sales/document/' . (int) $draft->sale_id), 'attr') ?>"><?= lang('Sales.document_open') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5 mt-4"><?= lang('Sales.documents_quotes') ?></h2>

    <form class="print_hide mb-3 d-flex flex-wrap align-items-end gap-2" method="get" id="quote_filters" role="search">
        <div>
            <label class="form-label" for="f_quote_status"><?= lang('Sales.document_status') ?></label>
            <select id="f_quote_status" name="quote_status" class="form-select form-select-sm">
                <option value=""><?= lang('Sales.document_all_statuses') ?></option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= $status->value ?>"<?= $quote_filter === $status ? ' selected' : '' ?>><?= esc(lang($status->languageKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
    </form>

    <?php if ($quotes === []): ?>
        <p class="text-body-secondary" id="quotes_empty"><?= lang('Sales.documents_quotes_empty') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="quotes_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Sales.document_number') ?></th>
                        <th scope="col"><?= lang('Sales.document_status') ?></th>
                        <th scope="col"><?= lang('Sales.date') ?></th>
                        <th scope="col"><?= lang('Sales.customer') ?></th>
                        <th scope="col"><?= lang('Sales.employee') ?></th>
                        <?php if ($show_branch): ?>
                            <th scope="col" class="document-branch"><?= lang('Sales.document_branch') ?></th>
                        <?php endif; ?>
                        <th scope="col"><?= lang('Sales.sub_total') ?></th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotes as $quote): ?>
                        <tr data-sale-id="<?= (int) $quote->sale_id ?>">
                            <td><bdi dir="ltr"><?= esc($quote->label) ?></bdi></td>
                            <td><span class="badge <?= $badges[$quote->status->value] ?>"><?= esc(lang($quote->status->languageKey())) ?></span></td>
                            <td><?= esc(to_date(strtotime((string) $quote->sale_time))) ?></td>
                            <td><?= esc((string) $quote->customer_name) ?></td>
                            <td><?= esc((string) $quote->employee_name) ?></td>
                            <?php if ($show_branch): ?>
                                <td class="document-branch"><?= esc((string) $quote->branch_code) ?></td>
                            <?php endif; ?>
                            <td><bdi dir="ltr"><?= to_currency($quote->subtotal) ?></bdi></td>
                            <td><a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('sales/document/' . (int) $quote->sale_id), 'attr') ?>"><?= lang('Sales.document_open') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
