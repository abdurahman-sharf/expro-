<?php

use App\Libraries\Integrations\Notifications;

/**
 * @var list<object> $rows
 * @var int          $total
 * @var int          $unread
 * @var int          $page
 * @var int          $pages
 * @var bool         $unreadOnly
 * @var bool         $enabled
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.notification-post').on('click', function() {
            $.post($(this).data('url'), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.notifications') ?> <span class="badge text-bg-primary" id="unread_count"><?= $unread ?></span></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('notifications') . ($unreadOnly ? '' : '?show=unread'), 'attr') ?>"><?= lang($unreadOnly ? 'Integrations.show_all' : 'Integrations.show_unread') ?></a>
    <?php if ($unread > 0): ?>
        <button type="button" class="btn btn-outline-primary btn-sm notification-post" id="read_all" data-url="<?= esc(base_url('notifications/read_all'), 'attr') ?>"><?= lang('Integrations.read_all') ?></button>
    <?php endif; ?>
</div>

<?php if (! $enabled): ?>
    <div class="alert alert-warning" id="notifications_off" role="status"><?= lang('Integrations.rules_off') ?></div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <p class="text-body-secondary" id="notifications_empty"><?= lang('Integrations.no_notifications') ?></p>
<?php else: ?>
    <ul class="list-group mb-3" id="notifications_list">
        <?php foreach ($rows as $row): ?>
            <li class="list-group-item d-flex align-items-start gap-2<?= (int) $row->is_read === 1 ? '' : ' fw-semibold' ?>" data-notification="<?= (int) $row->notification_id ?>">
                <span class="bi <?= (int) $row->is_read === 1 ? 'bi-envelope-open' : 'bi-envelope-fill' ?>" aria-hidden="true"></span>
                <div class="me-auto">
                    <div><?= esc(Notifications::message($row)) ?></div>
                    <div class="small text-body-secondary"><?= esc(lang('Integrations.rule_kind_' . $row->kind)) ?> · <bdi dir="ltr"><?= esc($row->created_at) ?></bdi><?= $row->branch_code !== null ? ' · ' . esc($row->branch_code) : '' ?></div>
                </div>
                <?php if ((int) $row->is_read !== 1): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm notification-post" data-url="<?= esc(base_url('notifications/read/' . (int) $row->notification_id), 'attr') ?>"><?= lang('Integrations.mark_read') ?></button>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
        <nav aria-label="<?= esc(lang('Integrations.pages'), 'attr') ?>">
            <ul class="pagination pagination-sm">
                <?php for ($number = 1; $number <= $pages; $number++): ?>
                    <li class="page-item<?= $number === $page ? ' active' : '' ?>"><a class="page-link" href="<?= esc(base_url('notifications') . '?' . http_build_query(['show' => $unreadOnly ? 'unread' : '', 'page' => $number]), 'attr') ?>"><?= $number ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?= view('partial/footer') ?>
