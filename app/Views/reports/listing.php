<?php
/**
 * @var array  $permission_ids
 * @var string $error
 */
$catalog = [...reportCatalog(openableReportPermissions($permission_ids)), ...familyReportCatalog($permission_ids)];
?>

<?= view('partial/header') ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<header class="report-head">
    <h2 class="report-title"><?= lang('Module.reports') ?></h2>
    <div class="report-search print_hide">
        <label for="report_filter" class="visually-hidden"><?= lang('Reports.search_reports') ?></label>
        <div class="input-group">
            <span class="input-group-text"><span class="bi bi-search" aria-hidden="true"></span></span>
            <input type="search" id="report_filter" class="form-control" placeholder="<?= lang('Reports.search_reports') ?>" autocomplete="off">
        </div>
    </div>
</header>

<?php foreach ($catalog as $groupIndex => $group): ?>
    <section class="report-group" aria-labelledby="report-group-<?= $groupIndex ?>">
        <h3 class="report-group-title" id="report-group-<?= $groupIndex ?>"><?= esc($group['label']) ?></h3>
        <div class="report-grid">
            <?php foreach ($group['subjects'] as $subject): ?>
                <article class="report-card">
                    <h4 class="report-card-title"><span class="bi <?= $subject['icon'] ?>" aria-hidden="true"></span> <?= esc($subject['label']) ?></h4>
                    <div class="report-links">
                        <?php foreach ($subject['links'] as $link): ?>
                            <a class="report-link" href="<?= $link['path'] ?>"><span class="bi <?= $link['icon'] ?>" aria-hidden="true"></span> <?= esc($link['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<p class="alert alert-secondary d-none" id="report_filter_empty" role="status"><?= lang('Reports.no_reports_matched') ?></p>

<script type="text/javascript">
    $(document).ready(function() {
        $('#report_filter').on('input', function() {
            const needle = $(this).val().trim().toLowerCase();
            let visible = 0;

            $('.report-card').each(function() {
                const match = needle === '' || $(this).text().toLowerCase().indexOf(needle) >= 0;
                $(this).toggleClass('d-none', !match);
                visible += match ? 1 : 0;
            });
            $('.report-group').each(function() {
                $(this).toggleClass('d-none', $(this).find('.report-card:not(.d-none)').length === 0);
            });
            $('#report_filter_empty').toggleClass('d-none', visible > 0);
        });
    });
</script>

<?= view('partial/footer') ?>
