<?php
/**
 * @var array{sales: list<array<string, mixed>>, payments: list<array<string, mixed>>} $sections
 * @var list<array{key: string, label: string}>                                        $pending
 * @var bool                                                                           $can_manage
 * @var bool                                                                           $can_review
 */
$slug = static fn (string $key): string => 'route_' . str_replace('.', '_', $key);
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const form = $('#routing_form');

        form.on('submit', function(event) {
            event.preventDefault();
            form.find('.is-invalid').removeClass('is-invalid');
            form.find('.routing-error').remove();

            $.post('accounting_routing/save', form.serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);

                    return;
                }

                $.each(response.errors || {}, function(key, message) {
                    const select = form.find('select').filter(function() {
                        return $(this).data('key') === key;
                    });

                    select.addClass('is-invalid').attr('aria-invalid', 'true');
                    $('<div class="invalid-feedback d-block routing-error"></div>').text(message).insertAfter(select);
                });
            }, 'json');
        });

        $('[data-restore-default]').on('click', function() {
            const select = $('#' + $(this).data('restore-default'));

            select.val(String(select.data('default') || ''));
        });
    });
</script>

<div class="acc-setup">
    <section class="acc-setup-card" aria-labelledby="routing_status_title">
        <h2 id="routing_status_title" class="acc-setup-title"><?= lang('Accounting_routing.status_title') ?></h2>

        <?php if ($pending === []): ?>
            <p class="acc-ready"><span class="bi bi-check-circle-fill text-success" aria-hidden="true"></span> <?= lang('Accounting_routing.complete') ?></p>
        <?php else: ?>
            <p class="mb-1"><span class="bi bi-x-circle-fill text-danger" aria-hidden="true"></span> <?= lang('Accounting_routing.incomplete', [(string) count($pending)]) ?></p>
            <ul class="acc-readiness">
                <?php foreach ($pending as $row): ?>
                    <li><a href="#<?= esc($slug($row['key']), 'attr') ?>"><?= esc($row['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="text-body-secondary small mb-0"><?= lang('Accounting_routing.company_note') ?></p>
    </section>

    <form id="routing_form" class="app-form" novalidate>
        <?php foreach (['sales' => 'sales_title', 'payments' => 'payments_title'] as $group => $titleKey): ?>
            <section class="acc-setup-card" aria-labelledby="<?= esc($group, 'attr') ?>_title">
                <h2 id="<?= esc($group, 'attr') ?>_title" class="acc-setup-title"><?= lang('Accounting_routing.' . $titleKey) ?></h2>
                <p class="text-body-secondary small"><?= lang('Accounting_routing.' . $group . '_desc') ?></p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 acc-routing" id="<?= esc($group, 'attr') ?>_routing">
                        <thead>
                            <tr>
                                <th scope="col"><?= lang('Accounting_routing.column_item') ?></th>
                                <th scope="col"><?= lang('Accounting_routing.column_account') ?></th>
                                <th scope="col"><?= lang('Accounting_routing.column_default') ?></th>
                                <th scope="col"><?= lang('Accounting_routing.column_status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sections[$group] as $row): ?>
                                <?php $id = $slug($row['key']); ?>
                                <tr id="<?= esc($id, 'attr') ?>">
                                    <th scope="row" class="fw-normal" data-label="<?= esc(lang('Accounting_routing.column_item'), 'attr') ?>"><?= $row['locked'] ? esc($row['label']) : '<label for="' . esc($id . '_account', 'attr') . '">' . esc($row['label']) . '</label>' ?></th>
                                    <td data-label="<?= esc(lang('Accounting_routing.column_account'), 'attr') ?>">
                                        <?php if ($row['locked']): ?>
                                            <span id="<?= esc($id . '_account', 'attr') ?>"><span class="bi bi-lock" aria-hidden="true"></span>
                                                <?= $row['account_text'] === '' ? esc(lang('Accounting_routing.not_set')) : '<bdi dir="ltr">' . esc($row['account_text']) . '</bdi>' ?></span>
                                            <p class="form-text mb-0"><?= lang('Accounting_routing.locked_note', [(string) $row['locked_to']]) ?></p>
                                        <?php else: ?>
                                            <select name="route[<?= esc($row['key'], 'attr') ?>]" id="<?= esc($id . '_account', 'attr') ?>" class="form-select form-select-sm" data-key="<?= esc($row['key'], 'attr') ?>" data-default="<?= esc((string) $row['default_id'], 'attr') ?>"<?= $can_manage ? '' : ' disabled' ?>>
                                                <option value=""><?= $row['required'] ? esc(lang('Accounting_routing.choose')) : esc(lang('Accounting_routing.not_used')) ?></option>
                                                <?php foreach ($row['options'] as $option): ?>
                                                    <option value="<?= (int) $option['id'] ?>"<?= $option['id'] === $row['account_id'] ? ' selected' : '' ?>><?= esc($option['text']) ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($row['account_id'] !== null && ! in_array($row['account_id'], array_column($row['options'], 'id'), true)): ?>
                                                    <option value="<?= (int) $row['account_id'] ?>" selected><?= esc($row['account_text']) ?></option>
                                                <?php endif; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= esc(lang('Accounting_routing.column_default'), 'attr') ?>">
                                        <?php if ($row['locked'] || $row['default_text'] === ''): ?>
                                            <span class="text-body-secondary">—</span>
                                        <?php else: ?>
                                            <bdi dir="ltr"><?= esc($row['default_text']) ?></bdi>
                                            <?php if ($can_manage): ?>
                                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" data-restore-default="<?= esc($id . '_account', 'attr') ?>"><?= lang('Accounting_routing.restore_default') ?></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= esc(lang('Accounting_routing.column_status'), 'attr') ?>">
                                        <?php
                                        $badge = match ($row['status']) {
                                            'complete' => 'text-bg-success',
                                            'optional' => 'text-bg-secondary',
                                            default    => 'text-bg-danger',
                                        };
                                ?>
                                        <span class="badge <?= $badge ?>"><?= lang('Accounting_routing.status_' . $row['status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if ($can_manage): ?>
            <button type="submit" class="btn btn-primary btn-sm"><?= lang('Common.submit') ?></button>
        <?php else: ?>
            <p class="form-text mb-0" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang($can_review ? 'Accounting_routing.scope_note' : 'Accounting_routing.permission_note') ?></p>
        <?php endif; ?>
    </form>
</div>

<?= view('partial/footer') ?>
