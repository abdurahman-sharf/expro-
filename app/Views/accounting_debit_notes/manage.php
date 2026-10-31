<?php
/**
 * @var list<object> $rows
 * @var int          $total
 * @var int          $offset
 * @var int          $page_size
 * @var array{status: string, search: string, from: string, to: string} $filters
 * @var bool         $enabled
 * @var bool         $can_manage
 * @var bool         $scopeNotice
 */
$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.debit-note-reverse', function() {
            dialog_support.init(this);
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Accounting_debit_notes.title') ?></h1>
    <?php if ($can_manage && $enabled): ?>
        <button class="btn btn-primary btn-sm modal-dlg" id="debit_note_new" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('accounting_debit_notes/form'), 'attr') ?>" title="<?= lang('Accounting_debit_notes.new') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_debit_notes.new') ?>
        </button>
    <?php endif; ?>
</div>

<?php if (! $enabled): ?>
    <div class="alert alert-info" id="debit_notes_off"><?= lang('Accounting_debit_notes.off') ?></div>
<?php endif; ?>

<?php if ($scopeNotice): ?>
    <div class="alert alert-secondary" id="debit_notes_scope"><?= lang('Accounting_debit_notes.scope_notice') ?></div>
<?php endif; ?>

<form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="debit_note_filters">
    <div>
        <label class="form-label" for="f_search"><?= lang('Accounting_debit_notes.search') ?></label>
        <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_status"><?= lang('Accounting_debit_notes.status') ?></label>
        <select class="form-select form-select-sm" id="f_status" name="status">
            <option value=""><?= lang('Accounting_debit_notes.status_all') ?></option>
            <option value="posted"<?= $filters['status'] === 'posted' ? ' selected' : '' ?>><?= lang('Accounting_debit_notes.status_posted') ?></option>
            <option value="reversed"<?= $filters['status'] === 'reversed' ? ' selected' : '' ?>><?= lang('Accounting_debit_notes.status_reversed') ?></option>
        </select>
    </div>
    <div>
        <label class="form-label" for="f_from"><?= lang('Accounting_debit_notes.date_from') ?></label>
        <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_to"><?= lang('Accounting_debit_notes.date_to') ?></label>
        <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
    </div>
    <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_debit_notes.filter') ?></button>
</form>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="debit_notes_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Accounting_debit_notes.number') ?></th>
                <th scope="col"><?= lang('Accounting_debit_notes.date') ?></th>
                <th scope="col"><?= lang('Accounting_debit_notes.customer') ?></th>
                <th scope="col"><?= lang('Accounting_debit_notes.branch') ?></th>
                <th scope="col" class="text-end"><?= lang('Accounting_debit_notes.amount') ?></th>
                <th scope="col" class="text-end"><?= lang('Accounting_debit_notes.tax') ?></th>
                <th scope="col" class="text-end"><?= lang('Accounting_debit_notes.total') ?></th>
                <th scope="col"><?= lang('Accounting_debit_notes.status') ?></th>
                <th scope="col"><?= lang('Accounting_debit_notes.note') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="text-body-secondary" id="debit_notes_empty"><?= lang('Accounting_debit_notes.empty') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="debit_note_<?= (int) $row->debit_note_id ?>">
                    <td><bdi dir="ltr"><?= esc($row->document_number ?? str_pad((string) $row->debit_note_id, 5, '0', STR_PAD_LEFT)) ?></bdi></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->note_date) ?></bdi></td>
                    <td><?= esc((string) $row->customer_name) ?></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= to_currency($row->amount) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= to_currency_tax($row->tax_amount) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= to_currency($row->total) ?></bdi></td>
                    <td><?= $row->status === 'posted' ? '<span class="badge text-bg-success">' . lang('Accounting_debit_notes.status_posted') . '</span>' : '<span class="badge text-bg-secondary" title="' . esc((string) $row->reverse_reason, 'attr') . '">' . lang('Accounting_debit_notes.status_reversed') . '</span>' ?></td>
                    <td><?= esc((string) $row->note) ?></td>
                    <td>
                        <?php if ($can_manage && $row->status === 'posted'): ?>
                            <button class="btn btn-outline-danger btn-sm modal-dlg debit-note-reverse" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('accounting_debit_notes/reverse_form/' . (int) $row->debit_note_id), 'attr') ?>" title="<?= lang('Accounting_debit_notes.reverse') ?>"><?= lang('Accounting_debit_notes.reverse') ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Accounting_debit_notes.title') ?>">
    <span class="text-body-secondary small"><?= lang('Accounting_debit_notes.count', [$total]) ?></span>
    <?php if ($offset > 0): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_debit_notes?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Accounting_debit_notes.previous') ?></a>
    <?php endif; ?>
    <?php if ($offset + $page_size < $total): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_debit_notes?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Accounting_debit_notes.next') ?></a>
    <?php endif; ?>
</nav>

<?= view('partial/footer') ?>
