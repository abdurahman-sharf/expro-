<?php

use App\Libraries\Accounting\ReturnDocument;

/**
 * @var string               $table_headers
 * @var list<object>         $branches
 * @var list<ReturnDocument> $documents
 * @var int                  $customer_id
 * @var array                $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const filterValues = function() {
            return {
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                customer: $('#f_customer').val(),
                customer_id: $('#f_customer_id').val(),
                document: $('#f_document').val(),
                branch_id: $('#f_branch').val() || ''
            };
        };

        table_support.init({
            resource: 'accounting_receivables/credit_notes',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'sale_id',
            cardTitle: ['number'],
            sortName: 'sale_time',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_receivables.credit_notes_empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            }
        });

        $('#credit_note_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#filters_reset').on('click', function() {
            $('#credit_note_filters')[0].reset();
            $('#f_customer_id').val('');
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?= view('accounting_receivables/tabs', ['active' => 'credit_notes', 'company_scope' => true]) ?>
    <p class="mb-0 text-body-secondary"><?= lang('Accounting_receivables.credit_notes_note') ?></p>
</div>

<form id="credit_note_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_receivables.filters'), 'attr') ?>">
    <input type="hidden" id="f_customer_id" value="<?= $customer_id > 0 ? (int) $customer_id : '' ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_receivables.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_receivables.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_customer"><?= lang('Accounting_receivables.customer') ?></label>
            <input type="text" id="f_customer" class="form-control form-control-sm" autocomplete="off">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_document"><?= lang('Accounting_receivables.document') ?></label>
            <select id="f_document" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_receivables.all_documents') ?></option>
                <?php foreach ($documents as $document): ?>
                    <option value="<?= esc($document->value, 'attr') ?>"><?= esc(lang($document->languageKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($branches !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_branch"><?= lang('Accounting_receivables.branch') ?></label>
                <select id="f_branch" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_receivables.all_branches') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_receivables.reset') ?></button>
        </div>
    </div>
</form>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
