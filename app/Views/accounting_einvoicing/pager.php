<?php
/**
 * @var int    $total
 * @var int    $offset
 * @var int    $size
 * @var string $name   The query parameter that carries the offset of this list.
 * @var string $anchor The id of the heading of the section the links return to.
 */
$kept = array_intersect_key((array) service('request')->getGet(), ['documents_offset' => true, 'customers_offset' => true]);
$link = static fn (int $to): string => site_url('accounting_einvoicing') . '?' . http_build_query([...$kept, $name => $to]) . '#' . $anchor;
?>

<?php if ($total > $size): ?>
    <nav class="d-flex gap-2 align-items-center" aria-label="<?= esc(lang('Accounting_einvoicing.page_label'), 'attr') ?>" data-pager="<?= esc($name, 'attr') ?>">
        <span class="text-body-secondary small"><?= lang('Accounting_einvoicing.page_range', [$offset + 1, min($offset + $size, $total), $total]) ?></span>
        <?php if ($offset > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= esc($link(max(0, $offset - $size)), 'attr') ?>"><?= lang('Accounting_einvoicing.page_previous') ?></a>
        <?php endif; ?>
        <?php if ($offset + $size < $total): ?>
            <a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= esc($link($offset + $size), 'attr') ?>"><?= lang('Accounting_einvoicing.page_next') ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
