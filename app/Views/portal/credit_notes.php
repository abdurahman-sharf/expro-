<?php
/**
 * @var array{total: int, rows: list<array<string, mixed>>} $found
 * @var int                                                 $page
 */
?>
<?= $this->extend('portal/layout') ?>

<?= $this->section('content') ?>
<h1 class="h4 mb-3"><?= esc(lang('Portal.menu_credit_notes')) ?></h1>
<div class="table-responsive mb-3">
    <table class="table table-sm bg-white" id="portal_credit_notes">
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Portal.kind')) ?></th>
                <th scope="col"><?= esc(lang('Portal.number')) ?></th>
                <th scope="col"><?= esc(lang('Portal.date')) ?></th>
                <th scope="col"><?= esc(lang('Portal.invoice_number')) ?></th>
                <th scope="col" class="text-end"><?= esc(lang('Portal.total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($found['rows'] as $row) : ?>
                <tr>
                    <td><?= esc($row['title']) ?></td>
                    <td><a href="<?= esc(site_url('portal/credit_note/' . (int) $row['sale_id']), 'attr') ?>"><bdi dir="ltr"><?= esc($row['number']) ?></bdi></a></td>
                    <td><bdi dir="ltr"><?= esc($row['date']) ?></bdi></td>
                    <td><bdi dir="ltr"><?= esc((string) $row['origin']) ?></bdi></td>
                    <td class="text-end portal-amount"><bdi dir="ltr"><?= esc($row['total']) ?></bdi></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($found['rows'] === []) : ?>
                <tr><td colspan="5" class="text-secondary"><?= esc(lang('Portal.no_rows')) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?= view('portal/pager', ['page' => $page, 'total' => $found['total'], 'path' => 'portal/credit_notes']) ?>
<?= $this->endSection() ?>
