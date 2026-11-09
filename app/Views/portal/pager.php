<?php

use App\Libraries\Portal\PortalDocuments;

/**
 * @var int    $page
 * @var int    $total
 * @var string $path
 */
$pages = max(1, (int) ceil($total / PortalDocuments::PAGE_SIZE));
?>
<?php if ($pages > 1) : ?>
    <nav class="d-flex gap-3 align-items-center" aria-label="<?= esc(lang('Portal.page_of', [$page, $pages]), 'attr') ?>">
        <?php if ($page > 1) : ?>
            <a href="<?= esc(site_url($path) . '?page=' . ($page - 1), 'attr') ?>"><?= esc(lang('Portal.previous')) ?></a>
        <?php endif; ?>
        <span><?= esc(lang('Portal.page_of', [$page, $pages])) ?></span>
        <?php if ($page < $pages) : ?>
            <a href="<?= esc(site_url($path) . '?page=' . ($page + 1), 'attr') ?>"><?= esc(lang('Portal.next')) ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
