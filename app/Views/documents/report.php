<?php
/**
 * @var string                                       $title
 * @var string                                       $date
 * @var int                                          $size
 * @var int                                          $columns
 * @var list<int>                                    $widths
 * @var list<string>                                 $intro
 * @var list<string>                                 $heading
 * @var list<array{cells: list<string>, span: bool}> $body
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
    <style>.doc { font-size: <?= (int) $size ?>pt; }</style>
</head>
<body>
<div class="doc" dir="<?= textDirection() ?>">
    <table class="doc-head">
        <tr>
            <td class="<?= $alignStart ?>">
                <div class="doc-title doc-title-small"><?= esc($title) ?></div>
                <?php foreach ($intro as $line): ?><div class="doc-grid-meta"><?= documentCell($line) ?></div><?php endforeach; ?>
            </td>
            <td class="doc-grid-meta <?= $alignEnd ?>"><?= documentIsolate($date) ?></td>
        </tr>
    </table>

    <table class="doc-items doc-grid">
        <?php if ($heading !== []): ?>
            <thead>
                <tr>
                    <?php foreach ($heading as $index => $cell): ?>
                        <th class="<?= $alignStart ?>" style="width: <?= $widths[$index] ?>%;"><?= esc($cell) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
        <?php endif; ?>
        <tbody>
            <?php foreach ($body as $index => $row): ?>
                <tr class="<?= $index % 2 === 1 ? 'alt' : '' ?>">
                    <?php if ($row['span']): ?>
                        <td class="<?= $alignStart ?>" colspan="<?= $columns ?>"><?= documentCell($row['cells'][0]) ?></td>
                    <?php else: ?>
                        <?php foreach ($row['cells'] as $cell): ?>
                            <td class="<?= $alignStart ?>"><?= documentCell($cell) ?></td>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
