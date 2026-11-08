<?php
/**
 * @var array<string, mixed> $receiving
 */
$profile = purchaseDocumentProfile($receiving);
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($profile['title'] . ' ' . $profile['number']) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('documents/purchase_invoice', ['receiving' => $receiving, 'context' => 'pdf']) ?>
</body>
</html>
