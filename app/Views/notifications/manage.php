<?php

use App\Libraries\Integrations\Notifications;

/**
 * @var list<object>                                                 $rows
 * @var list<array{label: string, date: string, rows: list<object>}> $days
 * @var int                                                          $total
 * @var int                                                          $unread
 * @var int                                                          $page
 * @var int                                                          $pages
 * @var int                                                          $from
 * @var int                                                          $to
 * @var bool                                                         $unreadOnly
 * @var bool                                                         $enabled
 */
$kindIcons = [
    'invoice_overdue'             => 'bi-receipt',
    'low_stock'                   => 'bi-box-seam',
    'cheque_due'                  => 'bi-calendar-check',
    'einvoice_certificate_expiry' => 'bi-shield-exclamation',
    'einvoice_reporting_late'     => 'bi-cloud-arrow-up',
];
$pageUrl = static fn (int $number): string => base_url('notifications') . '?' . http_build_query(array_filter(['show' => $unreadOnly ? 'unread' : null, 'page' => $number > 1 ? $number : null]));
?>

<?= view('partial/header') ?>

<div class="nt" id="notifications"
    data-unread-only="<?= $unreadOnly ? '1' : '0' ?>"
    data-marking="<?= esc(lang('Integrations.notification_marking'), 'attr') ?>"
    data-read-label="<?= esc(lang('Integrations.notification_read_label'), 'attr') ?>"
    data-read-state="<?= esc(lang('Integrations.notification_read_state'), 'attr') ?>"
    data-failed-title="<?= esc(lang('Integrations.notifications_failed_title'), 'attr') ?>"
    data-failed-text="<?= esc(lang('Integrations.notifications_failed_text'), 'attr') ?>"
    data-unread-count="<?= esc(lang('Integrations.notifications_unread_count', ['{0}']), 'attr') ?>">
    <div class="nt-head">
        <div class="nt-head-inner">
            <div class="nt-title-block">
                <div class="nt-title-row">
                    <h2 class="nt-title" id="nt_title"><?= lang('Integrations.notifications') ?></h2>
                    <span class="nt-count<?= $unread > 0 ? ' has-unread' : '' ?>" id="unread_count" aria-label="<?= esc(lang('Integrations.notifications_unread_count', [$unread]), 'attr') ?>"><bdi dir="ltr"><?= $unread ?></bdi></span>
                </div>
                <p class="nt-scope"><?= lang('Integrations.notifications_scope') ?></p>
            </div>
            <div class="nt-seg" role="group" aria-label="<?= esc(lang('Integrations.notifications_view'), 'attr') ?>">
                <a class="nt-seg-option<?= $unreadOnly ? '' : ' is-on' ?>" href="<?= esc(base_url('notifications'), 'attr') ?>"<?= $unreadOnly ? '' : ' aria-current="page"' ?>><?= lang('Integrations.notifications_all') ?></a>
                <a class="nt-seg-option<?= $unreadOnly ? ' is-on' : '' ?>" href="<?= esc(base_url('notifications') . '?show=unread', 'attr') ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>><?= lang('Integrations.notifications_unread_only') ?></a>
            </div>
            <?php if ($unread > 0): ?>
                <button type="button" class="btn btn-outline-secondary nt-mark-all" id="read_all" aria-describedby="nt_title" data-url="<?= esc(base_url('notifications/read_all'), 'attr') ?>">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> <span class="nt-label"><?= lang('Integrations.read_all') ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="nt-body">
        <?php if (! $enabled): ?>
            <div class="nt-alert" id="notifications_off" role="status">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <p><?= lang('Integrations.rules_off') ?></p>
            </div>
        <?php endif; ?>

        <section class="nt-card" aria-label="<?= esc(lang('Integrations.notifications_list'), 'attr') ?>">
            <div class="nt-empty" id="notifications_empty"<?= $rows === [] ? '' : ' hidden' ?>>
                <span class="nt-empty-icon" aria-hidden="true"><i class="bi bi-bell-slash"></i></span>
                <p><?= lang('Integrations.no_notifications') ?></p>
            </div>

            <?php if ($rows !== []): ?>
                <div id="notifications_list">
                    <?php foreach ($days as $day): ?>
                        <div class="nt-day">
                            <div class="nt-day-head">
                                <h3><?= esc($day['label']) ?></h3>
                                <bdi dir="ltr"><?= esc($day['date']) ?></bdi>
                            </div>
                            <ul class="nt-list">
                                <?php foreach ($day['rows'] as $row): ?>
                                    <?php
                                    $read     = (int) $row->is_read === 1;
                                    $kindName = lang('Integrations.rule_kind_' . $row->kind);
                                    ?>
                                    <li class="nt-item <?= $read ? 'is-read' : 'is-unread' ?> kind-<?= esc($row->kind, 'attr') ?>" data-notification="<?= (int) $row->notification_id ?>">
                                        <span class="nt-dot" aria-hidden="true"></span>
                                        <span class="nt-icon" aria-hidden="true"><i class="bi <?= $kindIcons[$row->kind] ?? 'bi-bell' ?>"></i></span>
                                        <div class="nt-meta">
                                            <span class="visually-hidden nt-state"><?= lang($read ? 'Integrations.notification_read_state' : 'Integrations.notification_unread_state') ?></span>
                                            <span class="nt-kind"><?= esc($kindName) ?></span>
                                            <?php if ($row->branch_code !== null): ?>
                                                <span class="nt-branch" aria-label="<?= esc(lang('Integrations.notification_branch', [$row->branch_code]), 'attr') ?>"><i class="bi bi-geo-alt" aria-hidden="true"></i> <bdi dir="ltr"><?= esc($row->branch_code) ?></bdi></span>
                                            <?php endif; ?>
                                        </div>
                                        <time class="nt-time" datetime="<?= esc($row->raised_iso, 'attr') ?>"><bdi dir="ltr"><?= esc($row->raised_date) ?></bdi> · <bdi dir="ltr"><?= esc($row->raised_time) ?></bdi></time>
                                        <p class="nt-text"><?= esc(Notifications::message($row)) ?></p>
                                        <div class="nt-action">
                                            <?php if ($read): ?>
                                                <span class="nt-read"><i class="bi bi-check2" aria-hidden="true"></i> <?= lang('Integrations.notification_read_label') ?></span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary nt-mark" data-url="<?= esc(base_url('notifications/read/' . (int) $row->notification_id), 'attr') ?>" aria-label="<?= esc(lang('Integrations.mark_read') . ': ' . $kindName . ' ' . $row->raised_date, 'attr') ?>">
                                                    <i class="bi bi-check2" aria-hidden="true"></i> <span class="nt-label"><?= lang('Integrations.mark_read') ?></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="nt-pager" aria-label="<?= esc(lang('Integrations.pages'), 'attr') ?>">
                        <p><?= esc(lang('Integrations.notifications_showing', [$from, $to, $total])) ?></p>
                        <div class="nt-pager-links">
                            <?php if ($page > 1): ?>
                                <a class="nt-page nt-page-step" href="<?= esc($pageUrl($page - 1), 'attr') ?>"><i class="bi bi-chevron-right nt-flip" aria-hidden="true"></i> <span class="nt-step-label"><?= lang('Integrations.notifications_previous') ?></span></a>
                            <?php else: ?>
                                <span class="nt-page nt-page-step is-disabled" aria-disabled="true"><i class="bi bi-chevron-right nt-flip" aria-hidden="true"></i> <span class="nt-step-label"><?= lang('Integrations.notifications_previous') ?></span></span>
                            <?php endif; ?>
                            <?php for ($number = 1; $number <= $pages; $number++): ?>
                                <a class="nt-page<?= $number === $page ? ' is-current' : '' ?>" href="<?= esc($pageUrl($number), 'attr') ?>" aria-label="<?= esc(lang('Integrations.notifications_page', [$number]), 'attr') ?>"<?= $number === $page ? ' aria-current="page"' : '' ?>><bdi dir="ltr"><?= $number ?></bdi></a>
                            <?php endfor; ?>
                            <?php if ($page < $pages): ?>
                                <a class="nt-page nt-page-step" href="<?= esc($pageUrl($page + 1), 'attr') ?>"><span class="nt-step-label"><?= lang('Integrations.notifications_next') ?></span> <i class="bi bi-chevron-left nt-flip" aria-hidden="true"></i></a>
                            <?php else: ?>
                                <span class="nt-page nt-page-step is-disabled" aria-disabled="true"><span class="nt-step-label"><?= lang('Integrations.notifications_next') ?></span> <i class="bi bi-chevron-left nt-flip" aria-hidden="true"></i></span>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= view('partial/footer') ?>
