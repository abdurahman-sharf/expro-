<?php
/**
 * @var string             $title
 * @var string             $subtitle
 * @var string             $date
 * @var list<string>       $columns
 * @var list<int>          $widths
 * @var list<list<string>> $rows
 */
$rtl        = isRtl();
$alignStart = $rtl ? 'al-r' : 'al-l';
$alignEnd   = $rtl ? 'al-l' : 'al-r';
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($title) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<div class="doc" dir="<?= textDirection() ?>">
    <table class="doc-head">
        <tr>
            <td class="<?= $alignStart ?>">
                <div class="doc-title doc-title-small"><?= esc($title) ?></div>
                <?php if ($subtitle !== ''): ?><div class="doc-grid-meta"><?= documentCell($subtitle) ?></div><?php endif; ?>
            </td>
            <td class="doc-grid-meta <?= $alignEnd ?>"><?= documentIsolate($date) ?></td>
        </tr>
    </table>

    <table class="doc-items doc-grid">
        <thead>
            <tr>
                <?php foreach ($columns as $index => $column): ?>
                    <th class="<?= $alignStart ?>" style="width: <?= $widths[$index] ?>%;"><?= esc($column) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $index => $row): ?>
                <tr class="<?= $index % 2 === 1 ? 'alt' : '' ?>">
                    <?php foreach ($row as $cell): ?>
                        <td class="<?= $alignStart ?>"><?= documentCell($cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
