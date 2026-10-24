<?php
/**
 * @var list<array{location_id: int, name: string, items: int, units: string, value: string, status: string, status_label: string, posted: bool, settleable: bool, messages: list<string>}> $stock
 * @var int $branch_id
 * @var bool $can_manage
 * @var string $start_date
 * @var bool $has_start
 */

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$badge     = static fn (string $status): string => match ($status) {
    'postable'         => 'text-bg-primary',
    'already_posted'   => 'text-bg-success',
    'blocked'          => 'text-bg-warning',
    'partially_posted' => 'text-bg-warning',
    default            => 'text-bg-secondary',
};
$ready = count(array_filter($stock, static fn (array $row): bool => $row['status'] === 'postable'));
?>

<section id="opening_stock" class="acc-opening-stock mt-4" aria-labelledby="opening_stock_title">
    <h2 id="opening_stock_title" class="h5"><?= lang('Accounting_periods.opening_stock_title') ?></h2>

    <?php if (! $has_start): ?>
        <p class="alert alert-warning" role="alert"><?= lang('Accounting_periods.opening_no_start') ?></p>
    <?php elseif ($stock === []): ?>
        <p class="text-body-secondary"><?= lang('Accounting_periods.opening_stock_none') ?></p>
    <?php else: ?>
        <p class="text-body-secondary"><?= esc(lang('Accounting_periods.opening_stock_intro', [$start_date])) ?></p>

        <ul id="stock_message_box" class="error_message_box" role="alert"></ul>

        <div class="acc-lines-wrap">
            <table class="table acc-lines" id="stock_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_periods.opening_stock_location') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_items') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_units') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_periods.opening_stock_value') ?></th>
                        <th scope="col"><?= lang('Accounting_periods.opening_stock_status') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_periods.opening_stock_actions') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock as $row): ?>
                        <tr id="stock_location_<?= (int) $row['location_id'] ?>">
                            <td data-label="<?= esc(lang('Accounting_periods.opening_stock_location'), 'attr') ?>">
                                <?= esc($row['name']) ?>
                                <?php foreach ($row['messages'] as $message): ?>
                                    <div class="small text-body-secondary stock-message"><?= esc($message) ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="<?= esc(lang('Accounting_periods.opening_stock_items'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= (int) $row['items'] ?></bdi></td>
                            <td data-label="<?= esc(lang('Accounting_periods.opening_stock_units'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($row['units']) ?></bdi></td>
                            <td data-label="<?= esc(lang('Accounting_periods.opening_stock_value'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc($row['value']) ?></bdi></td>
                            <td data-label="<?= esc(lang('Accounting_periods.opening_stock_status'), 'attr') ?>"><span class="badge <?= $badge($row['status']) ?>"><?= esc($row['status_label']) ?></span></td>
                            <td class="acc-line-actions">
                                <?php if ($can_manage && $row['status'] === 'postable'): ?>
                                    <button type="button" class="btn btn-primary btn-sm stock-post" data-location="<?= (int) $row['location_id'] ?>"><?= lang('Accounting_periods.opening_stock_post') ?></button>
                                <?php endif; ?>
                                <?php if ($can_manage && $row['settleable']): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.opening_stock_post'), 'attr') ?>" data-href="<?= esc('accounting_periods/opening/stock/differences_form?location=' . (int) $row['location_id'], 'attr') ?>" title="<?= esc(lang('Accounting_periods.opening_stock_differences_title'), 'attr') ?>">
                                        <span class="bi bi-exclamation-triangle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.opening_stock_differences_action') ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_manage && $row['posted']): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_journals.reverse'), 'attr') ?>" data-href="<?= esc('accounting_periods/opening/stock/reverse_form?location=' . (int) $row['location_id'], 'attr') ?>" title="<?= esc(lang('Accounting_periods.opening_stock_reverse_title'), 'attr') ?>">
                                        <span class="bi bi-arrow-counterclockwise" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.opening_stock_reverse') ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($can_manage && $ready > 1): ?>
            <button type="button" id="stock_post_all" class="btn btn-outline-primary btn-sm print_hide"><span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.opening_stock_post_all') ?></button>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <script type="text/javascript">
                $(document).ready(function() {
                    const text = <?= json_encode(['confirm' => lang('Accounting_periods.opening_stock_confirm_post'), 'failed' => lang('Accounting_journals.request_failed')], $jsonFlags) ?>;
                    let busy = false;

                    const post = function(locationId) {
                        if (busy || !confirm(text.confirm)) {
                            return;
                        }

                        busy = true;
                        $('#stock_message_box').empty();

                        $.post('accounting_periods/opening/stock/post', {branch_id: <?= (int) $branch_id ?>, location_id: locationId}, function(response) {
                            if (response.success) {
                                window.location.href = response.redirect;
                                return;
                            }
                            $('#stock_message_box').append($('<li></li>').text(response.message));
                            toast_support.show(response.message, 'danger');
                        }, 'json').fail(function() {
                            $('#stock_message_box').append($('<li></li>').text(text.failed));
                        }).always(function() {
                            busy = false;
                        });
                    };

                    $('#stock_table').on('click', '.stock-post', function() {
                        post($(this).data('location'));
                    });

                    $('#stock_post_all').on('click', function() {
                        post(0);
                    });
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</section>
