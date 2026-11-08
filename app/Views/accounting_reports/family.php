<?php
/**
 * @var string                     $key
 * @var string                     $family
 * @var ReportTable                $table
 * @var ReportTable                $all
 * @var string|null                $error
 * @var list<array<string, mixed>> $filters
 * @var array<string, string>      $values
 * @var Closure                    $choices
 * @var list<object>               $branches
 * @var list<int>                  $selected
 * @var ReportScope                $scope
 * @var string|null                $scope_label
 * @var int                        $page
 * @var int                        $pages
 * @var string                     $csvUrl
 * @var Closure                    $pageUrl
 * @var Closure                    $amount
 * @var Closure                    $quantity
 * @var Closure                    $date
 */

use App\Libraries\Accounting\FamilyReports\ColumnKind;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;

$address = base_url('accounting_reports/' . $key);
$cell    = static function (ReportRow $row, $column) use ($amount, $quantity, $date): string {
    $value = $row->cell($column->key);

    if ($value === '') {
        return '';
    }

    return match ($column->kind) {
        ColumnKind::Amount   => $amount($value),
        ColumnKind::Quantity => $quantity($value),
        ColumnKind::Date     => esc($date($value)),
        ColumnKind::Plain    => '<bdi dir="ltr">' . esc(is_numeric($value) && str_contains($value, '.') && preg_match('/^-?\d+\.0+$/', $value) === 1 ? (string) preg_replace('/\.0+$/', '', $value) : $value) . '</bdi>',
        default              => esc($value),
    };
};
$rowHtml = static function (ReportRow $row, array $columns) use ($cell): string {
    $html = '<tr' . ($row->total ? ' class="table-active fw-semibold" data-total="1"' : '') . '>';

    foreach ($columns as $index => $column) {
        $content = $cell($row, $column);

        if ($index === 0 && $row->url !== null && $content !== '') {
            $content = '<a href="' . esc($row->url, 'attr') . '">' . $content . '</a>';
        }

        $html .= '<td class="' . ($column->kind->numeric() ? 'text-end' : '') . '">' . $content . '</td>';
    }

    return $html . '</tr>';
};
$head = static function (array $columns): string {
    $html = '<thead><tr>';

    foreach ($columns as $column) {
        $html .= '<th scope="col" class="' . ($column->kind->numeric() ? 'text-end' : '') . '">' . esc($column->label) . '</th>';
    }

    return $html . '</tr></thead>';
};
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="export_csv" href="<?= esc($csvUrl, 'attr') ?>">
        <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_family_reports.csv') ?>
    </a>
    <?= view('partial/report_files', ['csvUrl' => $csvUrl, 'id' => 'export_csv']) ?>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc($address, 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <?php foreach ($filters as $filter): ?>
            <?php $name = (string) $filter['name'];
            $value      = $values[$name] ?? ''; ?>
            <?php if ($filter['type'] === 'flag'): ?>
                <fieldset class="acc-filter acc-filter-status">
                    <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
                    <label class="choice"><input type="checkbox" class="form-check-input" id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" value="1"<?= $value === '1' ? ' checked' : '' ?>> <?= lang('Accounting_family_reports.filter_' . $name) ?></label>
                </fieldset>
            <?php else: ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_<?= esc($name, 'attr') ?>"><?= lang('Accounting_family_reports.filter_' . $name) ?></label>
                    <?php if ($filter['type'] === 'select'): ?>
                        <select id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" class="form-select form-select-sm">
                            <?php foreach ($filter['options'] as $index => $option): ?>
                                <option value="<?= esc($option, 'attr') ?>"<?= $value === $option || ($value === '' && $index === 0) ? ' selected' : '' ?>><?= lang('Accounting_family_reports.opt_' . $name . '_' . $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($filter['type'] === 'choice'): ?>
                        <select id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" class="form-select form-select-sm">
                            <option value=""><?= lang('Accounting_family_reports.all') ?></option>
                            <?php foreach ($choices((string) $filter['source']) as $id => $label): ?>
                                <option value="<?= esc((string) $id, 'attr') ?>"<?= $value === (string) $id ? ' selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($filter['type'] === 'date'): ?>
                        <input type="date" id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" class="form-control form-control-sm" value="<?= esc($value, 'attr') ?>">
                    <?php elseif ($filter['type'] === 'number'): ?>
                        <input type="number" min="1" max="365" id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" class="form-control form-control-sm" value="<?= esc($value, 'attr') ?>">
                    <?php else: ?>
                        <input type="text" maxlength="100" id="f_<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>" class="form-control form-control-sm" value="<?= esc($value, 'attr') ?>">
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $selected, true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?><?= $branch->active ? '' : ' (' . esc(lang('Accounting_reports.branch_inactive')) . ')' ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc($address, 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="family_report" data-report="<?= esc($key, 'attr') ?>">
    <h1 class="h4"><?= esc($table->title) ?></h1>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($scope_label !== null): ?>
        <?php if ($scope->kind === ReportScopeKind::Partial): ?>
            <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scope_label) ?></p>
        <?php else: ?>
            <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scope_label) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($scope->isEmpty()): ?>
        <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
    <?php endif; ?>

    <?php if ($all->ties !== []): ?>
        <section id="report_ties" aria-label="<?= esc(lang('Accounting_family_reports.ties_title'), 'attr') ?>">
            <?php foreach ($all->ties as $index => $tie): ?>
                <?php if ($tie->ties()): ?>
                    <p class="text-success mb-1" data-tie="<?= $index ?>" data-status="ties"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= esc($tie->label) ?>: <?= $amount($tie->reported) ?></p>
                <?php else: ?>
                    <p class="alert alert-danger mb-1" role="alert" data-tie="<?= $index ?>" data-status="differs"><?= esc(lang('Accounting_family_reports.tie_differs', [$tie->label, $tie->reported, $tie->book, $tie->difference()])) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($all->rowCount() === 0): ?>
        <p class="text-body-secondary" id="report_empty"><?= lang('Accounting_family_reports.empty') ?></p>
    <?php endif; ?>

    <?php foreach ($table->blocks as $index => $block): ?>
        <section class="mb-4" data-block="<?= $index ?>">
            <?php if ($block->title !== null): ?>
                <h2 class="h6"><?= esc($block->title) ?></h2>
            <?php endif; ?>
            <?php if ($block->rows !== []): ?>
                <div class="table-responsive">
                    <table class="table table-sm family-table acc-lines">
                        <?= $head($table->columns) ?>
                        <tbody>
                            <?php foreach ($block->rows as $row): ?>
                                <?= $rowHtml($row, $table->columns) ?>
                            <?php endforeach; ?>
                            <?php if ($block->total !== null): ?>
                                <?= $rowHtml($block->total, $table->columns) ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($table->grand !== null && $all->rowCount() > 0): ?>
        <div class="table-responsive">
            <table class="table table-sm family-table acc-lines" id="report_grand">
                <tbody><?= $rowHtml($table->grand, $table->columns) ?></tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="print_hide d-flex gap-2 align-items-center" aria-label="<?= esc(lang('Accounting_family_reports.pages'), 'attr') ?>" id="report_pages">
            <?php if ($page > 1): ?>
                <a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= esc($pageUrl($page - 1), 'attr') ?>"><?= lang('Accounting_family_reports.previous') ?></a>
            <?php endif; ?>
            <span class="small"><?= esc(lang('Accounting_family_reports.page_of', [$page, $pages])) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= esc($pageUrl($page + 1), 'attr') ?>"><?= lang('Accounting_family_reports.next') ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php foreach ($all->notes as $note): ?>
        <p class="small text-body-secondary mb-1 report-note"><?= esc($note) ?></p>
    <?php endforeach; ?>
</div>

<?= view('partial/footer') ?>
