<?php
/**
 * @var string                                   $table_headers
 * @var list<object>                             $accounts
 * @var array<string, mixed>|null                $picker The search box that replaces the list of accounts when there are many.
 * @var list<object>                             $branches
 * @var int|null                                 $current_branch
 * @var list<object>                             $creators
 * @var list<array{code: string, label: string}> $sources
 * @var bool                                     $can_draft
 * @var bool                                     $can_post
 * @var bool                                     $can_audit
 * @var string|null                              $notice
 * @var list<string>                             $preset_status The states the filter opens on, from the address.
 * @var array                                    $config
 */
$messages = [
    'post'   => lang('Accounting_journals.confirm_post_drafts'),
    'delete' => lang('Accounting_journals.confirm_delete_drafts'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$empty     = '<p class="mb-2">' . esc(lang('Accounting_journals.empty')) . '</p>'
    . ($can_draft ? '<a class="btn btn-primary btn-sm" href="' . esc(base_url('accounting_journals/new'), 'attr') . '">' . esc(lang('Accounting_journals.new')) . '</a>' : '');
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        const filterValues = function() {
            return {
                account_id: $('#f_account').val(),
                branch_id: $('#f_branch').val() || '',
                number: $('#f_number').val(),
                description: $('#f_description').val(),
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                source_type: $('#f_source').val(),
                status: $('.f-status:checked').map(function() {
                    return this.value;
                }).get(),
                amount_from: $('#f_amount_from').val(),
                amount_to: $('#f_amount_to').val(),
                created_from: $('#f_created_from').val(),
                created_to: $('#f_created_to').val(),
                created_by: $('#f_created_by').val()
            };
        };

        table_support.init({
            resource: 'accounting_journals',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'journal_id',
            cardTitle: ['number'],
            sortName: 'journal_date',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode($empty, $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            }
        });

        $('#journal_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        const picker = accounting_support.accountPicker('accounts_list', <?= json_encode($picker, $jsonFlags) ?>);

        $('#f_account_text').on('input change blur', function(event) {
            event.type === 'blur' ? picker.complete($(this), $('#f_account')) : picker.sync($(this), $('#f_account'));
        });

        $('#filters_reset').on('click', function() {
            $('#journal_filters')[0].reset();
            $('#f_account').val('');
            $('#f_account_text').removeClass('is-invalid');
            table_support.refresh();
        });

        const bulk = function(action, url) {
            const ids = table_support.selected_ids();

            if (!ids.length || !confirm(confirmations[action])) {
                return;
            }

            $.post(url, {'ids[]': ids}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                table_support.refresh();
            }, 'json');
        };

        $('#post_drafts').on('click', function() {
            bulk('post', 'accounting_journals/post_drafts');
        });

        $('#delete_drafts').on('click', function() {
            bulk('delete', 'accounting_journals/delete_drafts');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_draft): ?>
        <a class="btn btn-primary btn-sm float-end" href="<?= esc(base_url('accounting_journals/new'), 'attr') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.new') ?>
        </a>
    <?php endif; ?>
    <?php if ($can_audit): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_journals/audit'), 'attr') ?>">
            <span class="bi bi-clock-history" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.audit') ?>
        </a>
    <?php endif; ?>
</div>

<form id="journal_filters" class="acc-filters print_hide" role="search" aria-label="<?= lang('Accounting_journals.filters') ?>">
    <div class="acc-filter-row">
        <?php if ($branches !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_branch"><?= lang('Accounting_journals.branch') ?></label>
                <select id="f_branch" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_journals.all_branches') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"<?= $current_branch === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter">
            <label class="form-label" for="f_account_text"><?= lang('Accounting_journals.account') ?></label>
            <input type="text" id="f_account_text" class="form-control form-control-sm"<?= $picker === null ? ' list="accounts_list"' : '' ?> autocomplete="off" placeholder="<?= esc(lang('Accounting_journals.all_accounts'), 'attr') ?>">
            <input type="hidden" id="f_account" value="">
            <?php if ($picker === null): ?>
                <datalist id="accounts_list">
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= esc($account->code . ' - ' . $account->name, 'attr') ?>" data-id="<?= (int) $account->account_id ?>" data-code="<?= esc($account->code, 'attr') ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_number"><?= lang('Accounting_journals.number') ?></label>
            <input type="text" id="f_number" class="form-control form-control-sm ltr-input" dir="ltr">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_description"><?= lang('Accounting_journals.description') ?></label>
            <input type="text" id="f_description" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_journals.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_journals.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <fieldset class="acc-filter acc-filter-status">
            <legend class="form-label"><?= lang('Accounting.status') ?></legend>
            <?php foreach (['draft', 'posted', 'reversed'] as $state): ?>
                <label class="choice"><input type="checkbox" class="form-check-input f-status" value="<?= $state ?>"<?= in_array($state, $preset_status, true) ? ' checked' : '' ?>> <?= lang('Accounting.state_' . $state) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="acc-filter">
            <label class="form-label" for="f_source"><?= lang('Accounting_journals.source') ?></label>
            <select id="f_source" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_journals.all_sources') ?></option>
                <option value="manual"><?= lang('Accounting.source_manual') ?></option>
                <option value="reversal"><?= lang('Accounting.source_reversal') ?></option>
                <?php foreach ($sources as $source): ?>
                    <option value="<?= esc($source['code'], 'attr') ?>"><?= esc($source['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_journals.reset') ?></button>
            <button type="button" class="btn btn-link btn-sm" data-bs-toggle="collapse" data-bs-target="#advanced_filters" aria-expanded="false" aria-controls="advanced_filters"><?= lang('Accounting_journals.advanced_search') ?></button>
        </div>
    </div>

    <div id="advanced_filters" class="collapse">
        <div class="acc-filter-row">
            <div class="acc-filter">
                <label class="form-label" for="f_amount_from"><?= lang('Accounting_journals.amount_from') ?></label>
                <input type="text" id="f_amount_from" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_amount_to"><?= lang('Accounting_journals.amount_to') ?></label>
                <input type="text" id="f_amount_to" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_created_from"><?= lang('Accounting_journals.created_from') ?></label>
                <input type="date" id="f_created_from" class="form-control form-control-sm">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_created_to"><?= lang('Accounting_journals.created_to') ?></label>
                <input type="date" id="f_created_to" class="form-control form-control-sm">
            </div>
            <?php if ($creators !== []): ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_created_by"><?= lang('Accounting_journals.created_by') ?></label>
                    <select id="f_created_by" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_journals.all_creators') ?></option>
                        <?php foreach ($creators as $creator): ?>
                            <option value="<?= (int) $creator->employee_id ?>"><?= esc($creator->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<div id="toolbar">
    <div class="float-start d-flex flex-wrap align-items-center gap-2" role="toolbar">
        <?php if ($can_post): ?>
            <button type="button" id="post_drafts" class="btn btn-outline-secondary btn-sm print_hide">
                <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.post_drafts') ?>
            </button>
        <?php endif; ?>
        <?php if ($can_draft): ?>
            <button type="button" id="delete_drafts" class="btn btn-outline-secondary btn-sm print_hide">
                <span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.delete_drafts') ?>
            </button>
        <?php endif; ?>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
