<?php
/**
 * @var string               $type
 * @var array<string, mixed> $sale
 */
$profile = slipProfile($type, $sale, documentConfig());
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($profile['title'] . ' ' . $profile['number']) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('documents/slip', ['type' => $type, 'context' => 'pdf', 'sale' => $sale]) ?>
</body>
</html>
