<?php
/**
 * @var array{rows: list<array{id: int, name: string, kind: string, balance: string}>, total: string, more: int}|null $treasuries
 * @var string|null                                                                                                   $receivables
 * @var string|null                                                                                                   $payables
 * @var array{year: ?object, closed_through: ?string, days_left: ?int}|null                                           $period
 * @var list<array<string, string>>|null                                                                              $journals
 * @var list<array{label: string, url: string, icon: string}>                                                         $shortcuts
 * @var bool                                                                                                          $scope_notice
 * @var callable(string): string                                                                                      $amount
 * @var callable(string): string                                                                                      $date
 */
?>

<?= view('partial/header') ?>

<?php if ($scope_notice): ?>
    <p class="acc-scope-note text-secondary small" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= lang('Accounting.scope_note') ?></p>
<?php endif; ?>

<h2 class="acc-overview-title"><?= lang('Accounting_reports.overview_title') ?></h2>

<?php if ($shortcuts !== []): ?>
    <section class="acc-overview-card acc-overview-shortcuts" aria-labelledby="overview_shortcuts_title">
        <h3 id="overview_shortcuts_title"><?= lang('Accounting_reports.overview_shortcuts') ?></h3>
        <ul>
            <?php foreach ($shortcuts as $shortcut): ?>
                <li><a class="btn btn-sm btn-outline-primary" href="<?= esc($shortcut['url'], 'attr') ?>"><span class="bi <?= esc($shortcut['icon'], 'attr') ?>" aria-hidden="true"></span> <?= esc($shortcut['label']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<div class="acc-overview">
    <?php if ($receivables !== null): ?>
        <section class="acc-overview-card" aria-labelledby="overview_receivables_title">
            <h3 id="overview_receivables_title"><span class="bi bi-people" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_receivables') ?></h3>
            <p class="acc-overview-figure"><bdi dir="ltr"><?= esc($amount($receivables)) ?></bdi></p>
            <a class="acc-overview-link" href="<?= esc(site_url('accounting_receivables'), 'attr') ?>"><?= lang('Accounting_reports.overview_view_all') ?></a>
        </section>
    <?php endif; ?>

    <?php if ($payables !== null): ?>
        <section class="acc-overview-card" aria-labelledby="overview_payables_title">
            <h3 id="overview_payables_title"><span class="bi bi-truck" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_payables') ?></h3>
            <p class="acc-overview-figure"><bdi dir="ltr"><?= esc($amount($payables)) ?></bdi></p>
            <a class="acc-overview-link" href="<?= esc(site_url('accounting_payables'), 'attr') ?>"><?= lang('Accounting_reports.overview_view_all') ?></a>
        </section>
    <?php endif; ?>

    <?php if ($period !== null): ?>
        <section class="acc-overview-card" aria-labelledby="overview_period_title">
            <h3 id="overview_period_title"><span class="bi bi-calendar-check" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_period') ?></h3>
            <?php if ($period['year'] === null): ?>
                <p class="text-body-secondary"><?= lang('Accounting_reports.overview_no_year') ?></p>
            <?php else: ?>
                <p class="acc-overview-year">
                    <strong><?= esc($period['year']->name) ?></strong>
                    <span class="acc-chip is-on"><?= esc(lang('Accounting_periods.state_current')) ?></span>
                </p>
                <p class="text-body-secondary small"><bdi><?= esc($date((string) $period['year']->start_date)) ?></bdi> - <bdi><?= esc($date((string) $period['year']->end_date)) ?></bdi></p>
            <?php endif; ?>
            <p class="small"><?= $period['closed_through'] === null ? esc(lang('Accounting_periods.nothing_closed')) : esc(lang('Accounting_periods.closed_until', [$date($period['closed_through'])])) ?></p>
            <?php if ($period['days_left'] !== null): ?>
                <p class="small text-warning-emphasis"><span class="bi bi-exclamation-triangle" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_days_left', [$period['days_left']]) ?></p>
            <?php endif; ?>
            <a class="acc-overview-link" href="<?= esc(site_url('accounting_periods'), 'attr') ?>"><?= lang('Accounting_reports.overview_view_all') ?></a>
        </section>
    <?php endif; ?>

    <?php if ($treasuries !== null): ?>
        <section class="acc-overview-card acc-overview-wide" aria-labelledby="overview_treasuries_title">
            <h3 id="overview_treasuries_title"><span class="bi bi-bank" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_treasuries') ?></h3>
            <?php if ($treasuries['rows'] === []): ?>
                <p class="text-body-secondary"><?= lang('Accounting_reports.overview_no_treasuries') ?></p>
            <?php else: ?>
                <ul class="acc-overview-list">
                    <?php foreach ($treasuries['rows'] as $row): ?>
                        <li><span><?= esc($row['name']) ?></span><strong><bdi dir="ltr"><?= esc($amount($row['balance'])) ?></bdi></strong></li>
                    <?php endforeach; ?>
                    <?php if ($treasuries['more'] > 0): ?>
                        <li class="text-body-secondary small"><span><?= lang('Accounting_reports.overview_treasuries_more', [$treasuries['more']]) ?></span></li>
                    <?php endif; ?>
                    <li class="acc-overview-total"><span><?= lang('Accounting_reports.overview_total') ?></span><strong><bdi dir="ltr"><?= esc($amount($treasuries['total'])) ?></bdi></strong></li>
                </ul>
            <?php endif; ?>
            <a class="acc-overview-link" href="<?= esc(site_url('accounting_treasuries'), 'attr') ?>"><?= lang('Accounting_reports.overview_view_all') ?></a>
        </section>
    <?php endif; ?>

    <?php if ($journals !== null): ?>
        <section class="acc-overview-card acc-overview-wide" aria-labelledby="overview_journals_title">
            <h3 id="overview_journals_title"><span class="bi bi-journal-text" aria-hidden="true"></span> <?= lang('Accounting_reports.overview_journals') ?></h3>
            <?php if ($journals === []): ?>
                <p class="text-body-secondary"><?= lang('Accounting_reports.overview_no_journals') ?></p>
            <?php else: ?>
                <ul class="acc-overview-list">
                    <?php foreach ($journals as $journal): ?>
                        <li>
                            <a href="<?= esc($journal['url'], 'attr') ?>"><bdi dir="ltr"><?= esc($journal['number']) ?></bdi></a>
                            <span class="acc-overview-note"><bdi><?= esc($journal['date']) ?></bdi> <?= esc($journal['description']) ?></span>
                            <?= $journal['status'] ?>
                            <strong><bdi dir="ltr"><?= esc($journal['amount']) ?></bdi></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="acc-overview-link" href="<?= esc(site_url('accounting_journals'), 'attr') ?>"><?= lang('Accounting_reports.overview_view_all') ?></a>
        </section>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
