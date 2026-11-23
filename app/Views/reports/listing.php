<?php
/**
 * @var array  $permission_ids
 * @var string $error
 */
$catalog = allReportCatalogs($permission_ids);
?>

<?= view('partial/header') ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<header class="page-head report-hub-head">
    <div class="page-head-start">
        <h1 class="page-title"><?= lang('Module.reports') ?></h1>
    </div>
    <div class="page-head-actions report-search print_hide" role="search">
        <label for="report_filter" class="visually-hidden"><?= lang('Reports.search_reports') ?></label>
        <span class="report-search-field">
            <span class="bi bi-search" aria-hidden="true"></span>
            <input type="search" id="report_filter" class="form-control" placeholder="<?= lang('Reports.search_reports') ?>" autocomplete="off">
        </span>
    </div>
</header>

<?php foreach ($catalog as $groupIndex => $group): ?>
    <section class="report-group" aria-labelledby="report-group-<?= $groupIndex ?>">
        <h2 class="report-group-title" id="report-group-<?= $groupIndex ?>"><?= esc($group['label']) ?></h2>
        <div class="report-grid">
            <?php foreach ($group['subjects'] as $subject): ?>
                <article class="report-card">
                    <h3 class="report-card-title">
                        <span class="report-card-icon" aria-hidden="true"><span class="bi <?= $subject['icon'] ?>"></span></span>
                        <span class="report-card-name"><?= esc($subject['label']) ?></span>
                        <span class="report-card-count" aria-hidden="true"><?= count($subject['links']) ?></span>
                    </h3>
                    <ul class="report-links">
                        <?php foreach ($subject['links'] as $link): ?>
                            <li><a class="report-link" href="<?= $link['path'] ?>"><span class="bi <?= $link['icon'] ?>" aria-hidden="true"></span> <span class="report-link-label"><?= esc($link['label']) ?></span><span class="bi bi-chevron-left report-link-go" aria-hidden="true"></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<div class="empty-state d-none" id="report_filter_empty" role="status">
    <span class="empty-state-art" aria-hidden="true"><i class="bi bi-search"></i></span>
    <p class="empty-state-title"><?= lang('Reports.no_reports_matched') ?></p>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('#report_filter').on('input', function() {
            const needle = $(this).val().trim().toLowerCase();
            let visible = 0;

            // A card whose subject matches keeps all its reports; otherwise only the reports that match stay.
            $('.report-card').each(function() {
                const $card = $(this);
                const subjectMatch = needle === '' || $card.find('.report-card-name').text().toLowerCase().indexOf(needle) >= 0;
                let shown = 0;

                $card.find('.report-links > li').each(function() {
                    const match = subjectMatch || $(this).text().toLowerCase().indexOf(needle) >= 0;
                    $(this).toggleClass('d-none', !match);
                    shown += match ? 1 : 0;
                });
                $card.toggleClass('d-none', shown === 0);
                visible += shown > 0 ? 1 : 0;
            });
            $('.report-group').each(function() {
                $(this).toggleClass('d-none', $(this).find('.report-card:not(.d-none)').length === 0);
            });
            $('#report_filter_empty').toggleClass('d-none', visible > 0);
        });
    });
</script>

<?= view('partial/footer') ?>
