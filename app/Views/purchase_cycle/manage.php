<?php

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;

/**
 * @var PurchaseDocumentKind                                                              $kind
 * @var list<object>                                                                      $rows
 * @var int                                                                               $total
 * @var int                                                                               $offset
 * @var int                                                                               $pageSize
 * @var array{status: string, search: string, from: string, to: string, supplier_id: int} $filters
 * @var bool                                                                              $enabled
 * @var array{enabled: bool, allow_excess: bool, manual: array<string, bool>}             $settings
 * @var bool                                                                              $canAdd
 * @var bool                                                                              $canSettings
 * @var list<PurchaseDocumentStatus>                                                      $statuses
 */
$module = $kind->module();
$query  = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
$colour = static fn (string $status): string => match ($status) {
    'received', 'approved' => 'success',
    'cancelled' => 'secondary',
    'open'      => 'warning',
    default     => 'primary',
};
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Purchase_cycle.title_' . $kind->value) ?></h1>
    <?php if ($canAdd && $enabled): ?>
        <a class="btn btn-primary btn-sm" id="document_new" href="<?= esc(base_url($module . '/add'), 'attr') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Purchase_cycle.new_' . $kind->value) ?>
        </a>
    <?php endif; ?>
</div>

<?php if (! $enabled): ?>
    <div class="alert alert-info" id="purchase_cycle_off"><?= lang('Purchase_cycle.off') ?></div>
<?php endif; ?>

<?php if ($canSettings): ?>
    <?= form_open($module . '/settings', ['id' => 'purchase_cycle_settings', 'class' => 'app-form card card-body mb-3']) ?>
        <h2 class="h6"><?= lang('Purchase_cycle.settings') ?></h2>
        <div class="form-check form-switch">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" class="form-check-input" role="switch" id="pc_enabled" name="enabled" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="pc_enabled"><?= lang('Purchase_cycle.setting_enabled') ?></label>
        </div>
        <div class="form-check form-switch">
            <input type="hidden" name="allow_excess" value="0">
            <input type="checkbox" class="form-check-input" role="switch" id="pc_allow_excess" name="allow_excess" value="1"<?= $settings['allow_excess'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="pc_allow_excess"><?= lang('Purchase_cycle.setting_allow_excess') ?></label>
        </div>
        <?php foreach (PurchaseDocumentKind::cases() as $each): ?>
            <div class="form-check form-switch">
                <input type="hidden" name="manual_<?= esc($each->value, 'attr') ?>" value="0">
                <input type="checkbox" class="form-check-input" role="switch" id="pc_manual_<?= esc($each->value, 'attr') ?>" name="manual_<?= esc($each->value, 'attr') ?>" value="1"<?= $settings['manual'][$each->value] ? ' checked' : '' ?>>
                <label class="form-check-label" for="pc_manual_<?= esc($each->value, 'attr') ?>"><?= lang('Purchase_cycle.setting_manual_' . $each->value) ?></label>
            </div>
        <?php endforeach; ?>
        <p class="small text-body-secondary mb-2"><?= lang('Purchase_cycle.setting_note') ?></p>
        <div><button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Common.submit') ?></button></div>
    <?= form_close() ?>
<?php endif; ?>

<?php if ($enabled): ?>
    <form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="document_filters">
        <div>
            <label class="form-label" for="f_search"><?= lang('Purchase_cycle.search') ?></label>
            <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
        </div>
        <div>
            <label class="form-label" for="f_status"><?= lang('Purchase_cycle.status') ?></label>
            <select class="form-select form-select-sm" id="f_status" name="status">
                <option value=""><?= lang('Purchase_cycle.status_all') ?></option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= esc($status->value, 'attr') ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= lang($status->languageKey()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_from"><?= lang('Purchase_cycle.date_from') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div>
            <label class="form-label" for="f_to"><?= lang('Purchase_cycle.date_to') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Purchase_cycle.filter') ?></button>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle" id="documents_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Purchase_cycle.number') ?></th>
                    <th scope="col"><?= lang('Purchase_cycle.date') ?></th>
                    <?php if ($kind->hasSupplier()): ?>
                        <th scope="col"><?= lang('Purchase_cycle.supplier') ?></th>
                    <?php endif; ?>
                    <th scope="col"><?= lang('Purchase_cycle.branch') ?></th>
                    <th scope="col"><?= lang($kind->dueLabelKey()) ?></th>
                    <th scope="col" class="text-end"><?= lang('Purchase_cycle.gross') ?></th>
                    <th scope="col"><?= lang('Purchase_cycle.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-body-secondary" id="documents_empty"><?= lang('Purchase_cycle.empty') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr id="document_<?= (int) $row->document_id ?>">
                        <td><a href="<?= esc(base_url($module . '/view/' . (int) $row->document_id), 'attr') ?>"><bdi dir="ltr"><?= esc((string) ($row->document_number ?? $kind->documentType()->fallback((int) $row->document_id))) ?></bdi></a></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->document_date) ?></bdi></td>
                        <?php if ($kind->hasSupplier()): ?>
                            <td><?= esc((string) ($row->supplier_name ?? '')) ?></td>
                        <?php endif; ?>
                        <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc((string) ($row->due_date ?? '')) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= to_currency($row->gross) ?></bdi></td>
                        <td><span class="badge text-bg-<?= $colour((string) $row->derived_status) ?>"><?= lang('Purchase_cycle.status_' . $row->derived_status) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Purchase_cycle.title_' . $kind->value) ?>">
        <span class="text-body-secondary small"><?= lang('Purchase_cycle.count', [$total]) ?></span>
        <?php if ($offset > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module . '?' . $query(['offset' => max(0, $offset - $pageSize)])), 'attr') ?>"><?= lang('Purchase_cycle.previous') ?></a>
        <?php endif; ?>
        <?php if ($offset + $pageSize < $total): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module . '?' . $query(['offset' => $offset + $pageSize])), 'attr') ?>"><?= lang('Purchase_cycle.next') ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#purchase_cycle_settings').on('submit', function(event) {
            event.preventDefault();
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
