<?php
/**
 * Living reference of the design standard. Developer page, administrators only, kept in English like the code it documents.
 *
 * @var array $config
 */
$tokens = [
    'Brand'   => ['navy-950', 'navy-900', 'navy-800', 'blue-700', 'blue-600', 'blue-500', 'sky-400', 'blue-100', 'blue-50'],
    'Surface' => ['app-bg', 'surface', 'surface-2', 'line', 'line-strong', 'field'],
    'Text'    => ['text', 'text-2', 'muted', 'placeholder', 'white'],
    'State'   => ['success', 'success-bg', 'success-600', 'warning', 'warning-bg', 'danger', 'danger-bg', 'danger-600', 'danger-700', 'danger-border'],
];

$currency                                                        = ['prefix' => '', 'suffix' => ''];
$currency[is_right_side_currency_symbol() ? 'suffix' : 'prefix'] = $config['currency_symbol'];

$bar = ['kind' => 'bar', 'labels' => ['01/14', '01/15', '01/16', '01/17', '01/18', '01/19'], 'values' => [420, 380, 0, 910, 640, 1280], 'name' => 'Sales', 'xTitle' => '', 'yTitle' => '', 'currency' => $currency];
$pie = ['kind' => 'pie', 'labels' => ['Cash', 'Card', 'Gift card'], 'values' => [62, 31, 7], 'name' => 'Payments', 'xTitle' => '', 'yTitle' => '', 'currency' => ['prefix' => '', 'suffix' => '']];

$rows = [
    ['id' => 1, 'name' => 'Basmati rice 5 kg', 'category' => 'Grocery', 'quantity' => '12', 'total' => to_currency(510)],
    ['id' => 2, 'name' => 'Olive oil 1 l', 'category' => 'Grocery', 'quantity' => '-2', 'total' => to_currency(-78)],
    ['id' => 3, 'name' => 'Paper tissues x10', 'category' => 'Household', 'quantity' => '1', 'total' => to_currency(17.5)],
];
?>

<?= view('partial/header', ['pageTitleOverride' => 'Style guide']) ?>

<script src="<?= assetUrl('js/chart.min.js') ?>"></script>

<?= view('reports/_head', ['title' => 'Design standard', 'subtitle' => '', 'sensitiveToggle' => false]) ?>

<p class="text-secondary">Every block below uses the real classes and helpers. Copy the markup, never restyle it. The rules are in <code>docs/ui-redesign/design-system.md</code> and the page skeletons in <code>docs/ui-redesign/patterns.md</code>.</p>

<section class="sg-section" aria-labelledby="sg-colors">
    <h2 class="sg-title" id="sg-colors">Colors (tokens)</h2>
    <?php foreach ($tokens as $group => $names): ?>
        <h3 class="sg-subtitle"><?= esc($group) ?></h3>
        <div class="sg-swatches">
            <?php foreach ($names as $name): ?>
                <div class="sg-swatch-item">
                    <span class="sg-swatch" data-token="<?= esc($name, 'attr') ?>"></span>
                    <code>--<?= esc($name) ?></code>
                    <span class="sg-value" data-token-value="<?= esc($name, 'attr') ?>"></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>

<section class="sg-section" aria-labelledby="sg-type">
    <h2 class="sg-title" id="sg-type">Typography</h2>
    <div class="card dash-card">
        <h1 class="h2">Heading 1 · الاول برو</h1>
        <h2 class="h4">Heading 2 · نظام مالي متكامل</h2>
        <h3 class="h5">Heading 3 · المبيعات والتقارير</h3>
        <p>Body text. Numbers and currency inside Arabic text are isolated: <bdi dir="ltr"><?= to_currency(-1234.5) ?></bdi> and <bdi dir="ltr">0123456789</bdi>.</p>
        <p class="text-secondary">Secondary text for hints and descriptions.</p>
        <p class="small mb-0">Small text for footnotes · <a href="<?= current_url() ?>">a link</a> · <code>monospace</code></p>
    </div>
</section>

<section class="sg-section" aria-labelledby="sg-buttons">
    <h2 class="sg-title" id="sg-buttons">Buttons</h2>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-primary btn-sm"><span class="bi bi-plus-lg" aria-hidden="true"></span> Primary</button>
        <button type="button" class="btn btn-outline-secondary btn-sm">Secondary</button>
        <button type="button" class="btn btn-success btn-sm">Success</button>
        <button type="button" class="btn btn-danger btn-sm">Danger</button>
        <button type="button" class="btn btn-outline-danger btn-sm"><span class="bi bi-trash" aria-hidden="true"></span> Outline danger</button>
        <button type="button" class="btn btn-link btn-sm">Link</button>
        <button type="button" class="btn btn-primary btn-sm" disabled>Disabled</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" aria-label="Icon only"><span class="bi bi-three-dots" aria-hidden="true"></span></button>
    </div>
    <p class="text-secondary mb-0">Icon-only buttons always carry an <code>aria-label</code>. One primary action per toolbar.</p>
</section>

<section class="sg-section" aria-labelledby="sg-forms">
    <h2 class="sg-title" id="sg-forms">Forms</h2>
    <p class="text-secondary">Modal form (<code>.app-form</code> with <code>.field</code>), opened from a list. Settings rows (<code>.row.mb-3</code>) are shown below.</p>
    <button type="button" class="btn btn-primary btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#sg_modal"><span class="bi bi-window" aria-hidden="true"></span> Open sample form</button>

    <div class="tab-content settings-content">
        <div class="tab-pane active show" role="tabpanel" tabindex="0" aria-label="Settings row sample">
            <form class="app-form" id="sg_settings" action="<?= current_url() ?>">
                <div class="row mb-3">
                    <label class="col-form-label col-3 col-md-2 text-danger required" for="sg_company">Company name</label>
                    <div class="col-9 col-md-6 col-lg-5 col-xl-4"><input type="text" id="sg_company" class="form-control form-control-sm" value="<?= esc($config['company']) ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-form-label col-3 col-md-2" for="sg_select">Language</label>
                    <div class="col-9 col-md-4 col-lg-3">
                        <select id="sg_select" class="form-select form-select-sm"><option>العربية</option><option>English</option></select>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-form-label col-3 col-md-2" for="sg_amount">Amount</label>
                    <div class="col-9 col-md-4 col-lg-3">
                        <div class="input-group">
                            <span class="input-group-text"><?= esc($config['currency_symbol']) ?></span>
                            <input type="number" id="sg_amount" class="form-control form-control-sm" value="25">
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-form-label col-3 col-md-2" for="sg_check">Enabled</label>
                    <div class="col-9"><input type="checkbox" id="sg_check" class="form-check-input" checked></div>
                </div>
                <div class="settings-actions">
                    <button type="button" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="sg-section" aria-labelledby="sg-feedback">
    <h2 class="sg-title" id="sg-feedback">Feedback</h2>
    <div class="alert alert-info" role="status">Information: neutral message or empty state.</div>
    <div class="alert alert-success" role="status">Success: the action finished.</div>
    <div class="alert alert-warning" role="alert">Warning: needs attention.</div>
    <div class="alert alert-danger" role="alert">Error: the action failed.</div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="sg_notify"><span class="bi bi-bell" aria-hidden="true"></span> Show a notification</button>
</section>

<section class="sg-section" aria-labelledby="sg-cards">
    <h2 class="sg-title" id="sg-cards">KPI cards and lists</h2>
    <div class="report-kpis dash-kpis">
        <div class="report-kpi">
            <span class="report-kpi-label">Today</span>
            <bdi dir="ltr" class="report-kpi-value"><?= to_currency(1385) ?></bdi>
            <span class="kpi-note"><bdi dir="ltr">15</bdi> sales</span>
            <span class="kpi-delta is-up"><span class="bi bi-arrow-up-short" aria-hidden="true"></span><bdi dir="ltr">12.5%</bdi></span>
        </div>
        <div class="report-kpi">
            <span class="report-kpi-label">Expenses</span>
            <bdi dir="ltr" class="report-kpi-value"><?= to_currency(6020.5) ?></bdi>
            <span class="kpi-delta is-down"><span class="bi bi-arrow-down-short" aria-hidden="true"></span><bdi dir="ltr">3.1%</bdi></span>
        </div>
        <a class="report-kpi kpi-link" href="<?= current_url() ?>">
            <span class="report-kpi-label">Low stock</span>
            <bdi dir="ltr" class="report-kpi-value text-danger">5</bdi>
        </a>
        <div class="report-kpi is-sensitive">
            <span class="report-kpi-label">Sensitive (cost, profit)</span>
            <bdi dir="ltr" class="report-kpi-value"><?= to_currency(910) ?></bdi>
        </div>
    </div>
    <div class="card dash-card">
        <h3 class="dash-card-title">List</h3>
        <ol class="dash-list">
            <?php foreach ($rows as $row): ?>
                <li><span class="dash-list-name"><?= esc($row['name']) ?></span><bdi dir="ltr" class="dash-list-value"><?= esc($row['quantity']) ?> &middot; <?= $row['total'] ?></bdi></li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="sg-section" aria-labelledby="sg-tables">
    <h2 class="sg-title" id="sg-tables">Tables</h2>
    <p class="text-secondary">Lists use <code>table_support.init</code> (server data) and reports use <code>table_support.report_options</code>. Cards on phones, numbers isolated LTR.</p>
    <div id="table_holder"><table id="sg_table"></table></div>
</section>

<section class="sg-section" aria-labelledby="sg-tabs">
    <h2 class="sg-title" id="sg-tabs">Tabs</h2>
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="sg_tab_a" data-bs-toggle="tab" data-bs-target="#sg_pane_a" type="button" role="tab" aria-controls="sg_pane_a" aria-selected="true">First</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="sg_tab_b" data-bs-toggle="tab" data-bs-target="#sg_pane_b" type="button" role="tab" aria-controls="sg_pane_b" aria-selected="false">Second</button></li>
    </ul>
    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="sg_pane_a" role="tabpanel" aria-labelledby="sg_tab_a" tabindex="0">First pane</div>
        <div class="tab-pane fade" id="sg_pane_b" role="tabpanel" aria-labelledby="sg_tab_b" tabindex="0">Second pane</div>
    </div>
</section>

<section class="sg-section" aria-labelledby="sg-charts">
    <h2 class="sg-title" id="sg-charts">Charts</h2>
    <div class="sg-charts">
        <div class="card chart-card">
            <div class="chart-box" data-kind="bar"><canvas id="sg_bar" role="img" aria-label="Bar chart sample" data-chart="<?= esc(json_encode($bar, JSON_UNESCAPED_UNICODE), 'attr') ?>"></canvas></div>
        </div>
        <div class="card chart-card">
            <div class="chart-box" data-kind="pie"><canvas id="sg_pie" role="img" aria-label="Pie chart sample" data-chart="<?= esc(json_encode($pie, JSON_UNESCAPED_UNICODE), 'attr') ?>"></canvas></div>
        </div>
    </div>
</section>

<section class="sg-section" aria-labelledby="sg-icons">
    <h2 class="sg-title" id="sg-icons">Icons</h2>
    <p class="text-secondary">Bootstrap Icons only, decorative ones with <code>aria-hidden="true"</code>.</p>
    <div class="sg-icons">
        <?php foreach (['house', 'cart3', 'box-seam', 'people', 'truck', 'receipt', 'bar-chart-line', 'gear', 'search', 'plus-lg', 'pencil-square', 'trash', 'printer', 'file-earmark-pdf', 'download', 'funnel', 'calendar3', 'check-circle', 'exclamation-triangle', 'info-circle'] as $icon): ?>
            <span class="sg-icon"><span class="bi bi-<?= $icon ?>" aria-hidden="true"></span><code><?= $icon ?></code></span>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal fade" id="sg_modal" tabindex="-1" aria-labelledby="sg_modal_title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="sg_modal_title">Sample form</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= lang('Common.close') ?>"></button>
            </div>
            <div class="modal-body">
                <div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
                <ul id="sg_errors" class="error_message_box"></ul>
                <form class="app-form" id="sg_form" action="<?= current_url() ?>">
                    <fieldset>
                        <div class="field">
                            <label class="form-label text-danger required" for="sg_name">Name</label>
                            <div class="field-control"><input type="text" name="name" id="sg_name" class="form-control form-control-sm"></div>
                        </div>
                        <div class="field">
                            <label class="form-label" for="sg_notes">Notes</label>
                            <div class="field-control"><textarea name="notes" id="sg_notes" class="form-control form-control-sm"></textarea></div>
                        </div>
                    </fieldset>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><?= lang('Common.close') ?></button>
                <button type="submit" form="sg_form" class="btn btn-primary btn-sm"><?= lang('Common.submit') ?></button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const root = getComputedStyle(document.documentElement);
        $('[data-token-value]').each(function() {
            $(this).text(root.getPropertyValue('--' + $(this).data('token-value')).trim());
        });

        const columns = [
            {field: 'name', title: 'Name', sortable: true},
            {field: 'category', title: 'Category', sortable: true},
            {field: 'quantity', title: 'Quantity', sortable: true},
            {field: 'total', title: 'Total', sortable: true}
        ];

        $('#sg_table').bootstrapTable($.extend(table_support.report_options(columns), {
            data: <?= json_encode($rows) ?>,
            iconSize: 'sm',
            search: true,
            escape: true
        }));

        $('#sg_notify').on('click', function() {
            notify_support.show('Saved', 'success');
        });

        $('#sg_settings').on('submit', function(event) {
            event.preventDefault();
        });

        $('#sg_form').validate($.extend({
            submitHandler: function() {
                bootstrap.Modal.getInstance(document.getElementById('sg_modal')).hide();
                notify_support.show('Saved', 'success');
            },
            errorLabelContainer: '#sg_errors',
            rules: {name: 'required'},
            messages: {name: 'The name is required.'}
        }, form_support.error));
    });
</script>

<?= view('partial/footer') ?>
