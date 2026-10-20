<?php
/**
 * @var string $type
 * @var array  $sale
 */
$profile = documentProfile($type, $sale);
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($profile['title'] . ' ' . $profile['number']) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('documents/document', ['type' => $type, 'context' => 'pdf', 'sale' => $sale]) ?>
</body>
</html>
