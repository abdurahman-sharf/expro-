<?php
/**
 * @var string                                  $titleKey
 * @var string                                  $route
 * @var ReportTable|null                        $table
 * @var DateFilter                              $dates
 * @var ReportScope                             $scope
 * @var list<object>                            $branches
 * @var string|null                             $scopeLabel
 * @var string|null                             $error
 * @var list<array<string, mixed>>              $fields
 * @var array<string, string>                   $groups
 * @var string                                  $group
 * @var string                                  $groupLabelKey
 * @var bool                                    $details
 * @var bool                                    $offersView
 * @var string                                  $csvUrl
 * @var list<array{label: string, url: string}> $siblings
 * @var Closure                                 $amount
 * @var Closure                                 $quantity
 * @var Closure                                 $percent
 * @var Closure                                 $date
 */

use App\Libraries\Accounting\OperationalReports\ColumnKind;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;

$cell = static function (ColumnKind $kind, string $value) use ($amount, $quantity, $percent, $date): string {
    return match ($kind) {
        ColumnKind::Amount   => $amount($value),
        ColumnKind::Quantity => $quantity($value),
        ColumnKind::Percent  => $percent($value),
        ColumnKind::Date     => esc($value === '' ? '' : $date($value)),
        default              => esc($value),
    };
};
$headingText = static function (array $heading) use ($date): string {
    if (($heading['labelKind'] ?? 'text') === 'date') {
        return esc($date((string) $heading['label']));
    }

    if (($heading['labelKind'] ?? 'text') === 'range') {
        [$start, $end] = explode('|', (string) $heading['label']);

        return esc($date($start) . ' - ' . $date($end));
    }

    return esc((string) $heading['label']);
};

$leading = 0;

foreach ($table?->columns ?? [] as $column) {
    if ($column->kind->isNumber()) {
        break;
    }

    $leading++;
}
?>

<?= view('partial/header') ?>

<div id="title_bar" class="btn-toolbar">
    <h1 class="h4"><?= lang($titleKey) ?></h1>
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <?php if ($table !== null && ! $table->isRefused()): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" id="report_csv" href="<?= esc($csvUrl, 'attr') ?>">
            <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?>
        </a>
        <?= view('partial/report_files', ['csvUrl' => $csvUrl, 'id' => 'report_csv']) ?>
    <?php endif; ?>
    <?php foreach ($siblings as $sibling): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc($sibling['url'], 'attr') ?>"><?= esc($sibling['label']) ?></a>
    <?php endforeach; ?>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url($route), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <input type="hidden" name="show" value="1">
    <div class="acc-filter-row">
        <?php if ($groups !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_group"><?= lang($groupLabelKey) ?></label>
                <select id="f_group" name="group" class="form-select form-select-sm">
                    <?php foreach ($groups as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>"<?= $value === $group ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($offersView): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_view"><?= lang('Accounting_operational_reports.view') ?></label>
                <select id="f_view" name="view" class="form-select form-select-sm">
                    <option value="summary"<?= $details ? '' : ' selected' ?>><?= lang('Accounting_operational_reports.view_summary') ?></option>
                    <option value="details"<?= $details ? ' selected' : '' ?>><?= lang('Accounting_operational_reports.view_details') ?></option>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter">
            <label class="form-label" for="f_preset"><?= lang('Accounting_operational_reports.preset') ?></label>
            <select id="f_preset" name="preset" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_operational_reports.preset_none') ?></option>
                <?php foreach (DateFilter::PRESETS as $preset): ?>
                    <option value="<?= esc($preset, 'attr') ?>"<?= $dates->preset === $preset ? ' selected' : '' ?>><?= lang('Accounting_operational_reports.preset_' . $preset) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_mode"><?= lang('Accounting_operational_reports.date_mode') ?></label>
            <select id="f_mode" name="mode" class="form-select form-select-sm">
                <?php foreach (DateFilter::MODES as $mode): ?>
                    <option value="<?= esc($mode, 'attr') ?>"<?= $dates->mode === $mode ? ' selected' : '' ?>><?= lang('Accounting_operational_reports.mode_' . $mode) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_from"><?= lang('Accounting_reports.from') ?></label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($dates->fromInput, 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang('Accounting_reports.to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($dates->toInput, 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date"><?= lang('Accounting_operational_reports.single_date') ?></label>
            <input type="date" id="f_date" name="date" class="form-control form-control-sm" value="<?= esc($dates->dateInput, 'attr') ?>">
        </div>
        <?php foreach ($fields as $field): ?>
            <?php if ($field['type'] === 'select'): ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_<?= esc($field['name'], 'attr') ?>"><?= lang($field['labelKey']) ?></label>
                    <select id="f_<?= esc($field['name'], 'attr') ?>" name="<?= esc($field['name'], 'attr') ?>" class="form-select form-select-sm">
                        <?php foreach ($field['options'] as $value => $label): ?>
                            <option value="<?= esc((string) $value, 'attr') ?>"<?= (string) $value === (string) $field['value'] ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($field['type'] === 'picker'): ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_<?= esc($field['name'], 'attr') ?>_text"><?= lang($field['labelKey']) ?></label>
                    <input type="text" id="f_<?= esc($field['name'], 'attr') ?>_text" class="form-control form-control-sm" autocomplete="off" value="<?= esc($field['picker']['initial'][0]['label'] ?? '', 'attr') ?>" placeholder="<?= esc(lang($field['allKey']), 'attr') ?>">
                    <input type="hidden" id="f_<?= esc($field['name'], 'attr') ?>" name="<?= esc($field['name'], 'attr') ?>" value="<?= esc((string) ($field['picker']['initial'][0]['id'] ?? ''), 'attr') ?>">
                    <div class="invalid-feedback" role="alert"><?= esc(lang($field['chooseKey'])) ?></div>
                </div>
            <?php elseif ($field['type'] === 'multi'): ?>
                <fieldset class="acc-filter acc-filter-status" id="f_<?= esc($field['name'], 'attr') ?>">
                    <legend class="form-label"><?= lang($field['labelKey']) ?></legend>
                    <?php foreach ($field['options'] as $value => $label): ?>
                        <label class="choice"><input type="checkbox" class="form-check-input" name="<?= esc($field['name'], 'attr') ?>[]" value="<?= esc((string) $value, 'attr') ?>"<?= in_array((string) $value, $field['value'], true) ? ' checked' : '' ?>> <?= esc($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php else: ?>
                <fieldset class="acc-filter acc-filter-status">
                    <legend class="form-label"><?= lang($field['labelKey']) ?></legend>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="<?= esc($field['name'], 'attr') ?>" value="1"<?= $field['value'] ? ' checked' : '' ?>> <?= lang($field['checkLabelKey'] ?? $field['labelKey']) ?></label>
                </fieldset>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $scope->ids, true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?><?= $branch->active ? '' : ' (' . esc(lang('Accounting_reports.branch_inactive')) . ')' ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary" id="report_run"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_operational_reports.show_report') ?></button>
            <a class="btn btn-outline-secondary" id="report_reset" href="<?= esc(base_url($route), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
</form>

<div class="report-result" id="operational_report">

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($table === null): ?>
        <div class="empty-state" id="report_not_run">
            <span class="empty-state-art" aria-hidden="true"><i class="bi bi-funnel"></i></span>
            <p class="empty-state-text"><?= lang('Accounting_operational_reports.not_run') ?></p>
        </div>
    <?php elseif ($table->isRefused()): ?>
        <p class="alert alert-warning" id="report_refused" role="alert"><span class="bi bi-exclamation-triangle" aria-hidden="true">&nbsp;</span><?= esc(lang((string) $table->refusal, $table->refusalArguments)) ?></p>
    <?php else: ?>
        <p class="text-body-secondary mb-2" id="report_period">
            <?= $table->from === null ? lang('Accounting_operational_reports.all_dates_until') : lang('Accounting_reports.from') . ': ' . esc($date($table->from)) . ' &middot; ' . lang('Accounting_reports.to') ?>: <?= esc($date($table->to)) ?>
        </p>

        <?php if ($scopeLabel !== null): ?>
            <p class="alert alert-secondary small" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
        <?php endif; ?>

        <?php if ($scope->isEmpty()): ?>
            <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
        <?php endif; ?>

        <?php if ($table->isEmpty()): ?>
            <p class="text-body-secondary" id="report_empty"><?= lang('Accounting_operational_reports.no_documents') ?></p>
        <?php endif; ?>

        <div class="table-responsive mb-3">
            <table class="table table-sm acc-lines" id="report_table">
                <thead>
                    <tr>
                        <?php foreach ($table->columns as $column): ?>
                            <th scope="col" class="<?= $column->kind->isNumber() ? 'text-end' : '' ?>"><?= lang($column->labelKey) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table->blocks as $block): ?>
                        <?php if ($block->heading !== []): ?>
                            <tr class="table-secondary" data-heading="1"><th scope="rowgroup" colspan="<?= count($table->columns) ?>"><?= $headingText($block->heading) ?></th></tr>
                        <?php endif; ?>
                        <?php foreach ($block->rows as $row): ?>
                            <tr data-row="1">
                                <?php foreach ($table->columns as $column): ?>
                                    <td data-label="<?= esc(lang($column->labelKey), 'attr') ?>" data-cell="<?= esc($column->key, 'attr') ?>" class="<?= $column->kind->isNumber() ? 'text-end acc-amount' : '' ?>"><?= $cell($column->kind, (string) ($row[$column->key] ?? '')) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($block->subtotal !== null): ?>
                            <tr class="table-light fw-bold" data-subtotal="1">
                                <?php foreach ($table->columns as $index => $column): ?>
                                    <?php if ($column->kind->isNumber()): ?>
                                        <td data-label="<?= esc(lang($column->labelKey), 'attr') ?>" data-cell="<?= esc($column->key, 'attr') ?>" class="text-end acc-amount"><?= $cell($column->kind, (string) ($block->subtotal[$column->key] ?? '')) ?></td>
                                    <?php else: ?>
                                        <td><?= $index === 0 ? lang('Accounting_operational_reports.subtotal') : '' ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold table-light" data-total="1">
                        <?php foreach ($table->columns as $index => $column): ?>
                            <?php if ($column->kind->isNumber()): ?>
                                <td data-label="<?= esc(lang($column->labelKey), 'attr') ?>" data-cell="<?= esc($column->key, 'attr') ?>" class="text-end acc-amount"><?= $cell($column->kind, (string) ($table->total[$column->key] ?? '')) ?></td>
                            <?php elseif ($index === 0): ?>
                                <th scope="row" colspan="<?= $leading ?>"><?= lang('Accounting_operational_reports.grand_total') ?></th>
                            <?php elseif ($index >= $leading): ?>
                                <td class="d-none d-md-table-cell"></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($table->ties !== []): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm acc-lines" id="report_ties">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_operational_reports.tie') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_operational_reports.tie_reported') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_operational_reports.tie_ledger') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_operational_reports.tie_difference') ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table->ties as $tie): ?>
                            <?php if ($tie->isRefused()): ?>
                                <tr data-tie="<?= esc($tie->labelKey, 'attr') ?>" data-status="refused">
                                    <td><?= lang($tie->labelKey) ?></td>
                                    <td colspan="4" class="text-warning-emphasis"><span class="bi bi-exclamation-triangle" aria-hidden="true">&nbsp;</span><?= esc(lang((string) $tie->refusal, $tie->refusalArguments)) ?></td>
                                </tr>
                                <?php continue; ?>
                            <?php endif; ?>
                            <tr data-tie="<?= esc($tie->labelKey, 'attr') ?>">
                                <td><?= lang($tie->labelKey) ?></td>
                                <td class="text-end" data-cell="reported"><?= $amount($tie->reported) ?></td>
                                <td class="text-end" data-cell="ledger"><?= $amount($tie->ledger) ?></td>
                                <td class="text-end" data-cell="difference"><?= $amount($tie->difference()) ?></td>
                                <td><?= $tie->ties() ? '<span class="text-success">' . lang('Accounting_operational_reports.ties') . '</span>' : '<span class="text-danger">' . lang('Accounting_operational_reports.differs') . '</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php foreach ($table->notes as $note): ?>
            <p class="small text-body-secondary" data-note="<?= esc($note, 'attr') ?>"><?= lang($note) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php $pickerFields = array_values(array_filter($fields, static fn (array $field): bool => $field['type'] === 'picker')); ?>
<?php if ($pickerFields !== []): ?>
    <script type="text/javascript">
        $(function() {
            const fields = <?= json_encode(array_map(static fn (array $field): array => ['name' => $field['name'], 'picker' => $field['picker']], $pickerFields), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const pickers = {};

            const flag = function($text) {
                $text.attr('aria-invalid', $text.hasClass('is-invalid') ? 'true' : 'false');
            };

            $.each(fields, function(index, field) {
                const $text = $('#f_' + field.name + '_text');
                const $hidden = $('#f_' + field.name);
                pickers[field.name] = accounting_support.accountPicker('f_' + field.name + '_list', field.picker);

                $text.on('input change blur', function(event) {
                    event.type === 'blur' ? pickers[field.name].complete($(this), $hidden) : pickers[field.name].sync($(this), $hidden);
                    flag($(this));
                });
            });

            $('#report_filters').on('submit', function(event) {
                $.each(fields, function(index, field) {
                    const $text = $('#f_' + field.name + '_text');

                    pickers[field.name].sync($text, $('#f_' + field.name));
                    flag($text);

                    if ($text.hasClass('is-invalid')) {
                        event.preventDefault();
                        $text.trigger('focus');

                        return false;
                    }
                });
            });
        });
    </script>
<?php endif; ?>

<?= view('partial/footer') ?>
