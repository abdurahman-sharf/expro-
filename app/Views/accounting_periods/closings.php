<?php
/**
 * @var string                                                                                    $table_headers
 * @var string|null                                                                               $company_cutoff
 * @var bool                                                                                      $has_company
 * @var list<array{branch_id: int, code: string, name: string, cutoff: ?string, has_close: bool}> $branches
 * @var bool                                                                                      $show_branches
 * @var bool                                                                                      $can_close
 * @var bool                                                                                      $can_reopen
 * @var bool                                                                                      $is_unrestricted
 * @var string|null                                                                               $notice
 * @var array                                                                                     $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$closeUrl  = static fn (string $scope): string => esc('accounting_periods/closings/close_form?scope=' . $scope, 'attr');
$reopenUrl = static fn (string $scope): string => esc('accounting_periods/closings/reopen_form?scope=' . $scope, 'attr');
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_periods/closings',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'closed_period_id',
            cardTitle: ['scope'],
            queryParams: function(params) {
                return $.extend(params, {
                    scope: $('#f_scope').val(),
                    status: $('#f_status').val(),
                    date_from: $('#f_date_from').val(),
                    date_to: $('#f_date_to').val()
                });
            }
        });

        $('#closing_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#filters_reset').on('click', function() {
            $('#closing_filters')[0].reset();
            table_support.refresh();
        });
    });
</script>

<?= view('accounting_periods/tabs', ['active' => 'closings']) ?>

<?php if (! $can_close && ! $can_reopen): ?>
    <p class="text-body-secondary small" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_periods.permission_note') ?></p>
<?php endif; ?>

<section class="acc-closing-summary" aria-label="<?= esc(lang('Accounting_periods.summary'), 'attr') ?>">
    <div class="acc-closing-chip">
        <span class="acc-chip-label"><?= lang('Accounting_periods.scope_company') ?></span>
        <strong><?= $company_cutoff === null ? esc(lang('Accounting_periods.nothing_closed')) : esc(lang('Accounting_periods.closed_until', [$company_cutoff])) ?></strong>
        <?php if ($is_unrestricted && ($can_close || ($can_reopen && $has_company))): ?>
            <div class="mt-1">
                <?php if ($can_close): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.close_submit'), 'attr') ?>" data-href="<?= $closeUrl('company') ?>" title="<?= esc(lang('Accounting_periods.close_title'), 'attr') ?>"><?= lang('Accounting_periods.close_action') ?></button>
                <?php endif; ?>
                <?php if ($can_reopen && $has_company): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.reopen_submit'), 'attr') ?>" data-href="<?= $reopenUrl('company') ?>" title="<?= esc(lang('Accounting_periods.reopen_title'), 'attr') ?>"><?= lang('Accounting_periods.reopen_action') ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($show_branches): ?>
        <?php foreach ($branches as $branch): ?>
            <div class="acc-closing-chip">
                <span class="acc-chip-label"><bdi dir="ltr"><?= esc($branch['code']) ?></bdi> <?= esc($branch['name']) ?></span>
                <strong><?= $branch['cutoff'] === null ? esc(lang('Accounting_periods.nothing_closed')) : esc(lang('Accounting_periods.closed_until', [$branch['cutoff']])) ?></strong>
                <?php if ($can_close || ($can_reopen && $branch['has_close'])): ?>
                    <div class="mt-1">
                        <?php if ($can_close): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.close_submit'), 'attr') ?>" data-href="<?= $closeUrl((string) $branch['branch_id']) ?>" title="<?= esc(lang('Accounting_periods.close_title'), 'attr') ?>"><?= lang('Accounting_periods.close_action') ?></button>
                        <?php endif; ?>
                        <?php if ($can_reopen && $branch['has_close']): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.reopen_submit'), 'attr') ?>" data-href="<?= $reopenUrl((string) $branch['branch_id']) ?>" title="<?= esc(lang('Accounting_periods.reopen_title'), 'attr') ?>"><?= lang('Accounting_periods.reopen_action') ?></button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<form id="closing_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_journals.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <?php if ($show_branches): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_scope"><?= lang('Accounting_periods.scope') ?></label>
                <select id="f_scope" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_periods.all_scopes') ?></option>
                    <option value="company"><?= lang('Accounting_periods.scope_company') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['branch_id'] ?>"><?= esc($branch['code'] . ' - ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" id="f_scope" value="">
        <?php endif; ?>
        <div class="acc-filter">
            <label class="form-label" for="f_status"><?= lang('Accounting.status') ?></label>
            <select id="f_status" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_periods.all_statuses') ?></option>
                <option value="active"><?= lang('Accounting_periods.status_closed') ?></option>
                <option value="reopened"><?= lang('Accounting_periods.status_reopened') ?></option>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_journals.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_journals.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_journals.reset') ?></button>
        </div>
    </div>
</form>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
