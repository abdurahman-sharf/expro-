<?php
/**
 * @var string                                   $list
 * @var array{page: int, pages: int, total: int} $paging
 * @var Closure                                  $page_url
 */
?>
<?php if ($paging['pages'] > 1): ?>
    <nav class="print_hide d-flex flex-wrap align-items-center gap-2 mt-2" id="<?= esc($list, 'attr') ?>_pager" aria-label="<?= esc(lang('Sales.documents_pager'), 'attr') ?>">
        <?php if ($paging['page'] > 1): ?>
            <a class="btn btn-outline-secondary btn-sm" id="<?= esc($list, 'attr') ?>_previous" href="<?= esc($page_url($list, $paging['page'] - 1), 'attr') ?>" rel="prev"><span class="bi bi-chevron-left" aria-hidden="true">&nbsp;</span><?= lang('Sales.documents_previous') ?></a>
        <?php endif; ?>
        <span class="small text-body-secondary"><?= lang('Sales.documents_page_of', [$paging['page'], $paging['pages'], $paging['total']]) ?></span>
        <?php if ($paging['page'] < $paging['pages']): ?>
            <a class="btn btn-outline-secondary btn-sm" id="<?= esc($list, 'attr') ?>_next" href="<?= esc($page_url($list, $paging['page'] + 1), 'attr') ?>" rel="next"><?= lang('Sales.documents_next') ?><span class="bi bi-chevron-right" aria-hidden="true">&nbsp;</span></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
