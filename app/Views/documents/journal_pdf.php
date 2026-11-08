<?php
/**
 * @var object $journal
 * @var string $number
 * @var string $source
 * @var string $status
 * @var bool   $show_branch
 * @var string $currency
 */
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc(lang('Documents.journal') . ' ' . $number) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('documents/journal', ['journal' => $journal, 'number' => $number, 'source' => $source, 'status' => $status, 'show_branch' => $show_branch, 'currency' => $currency, 'context' => 'pdf']) ?>
</body>
</html>
