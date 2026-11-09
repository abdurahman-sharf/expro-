<?php
/**
 * @var array<string, mixed> $doc
 * @var string               $customerName
 */
?>
<!doctype html>
<html lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(textDirection(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($doc['title'] . ' ' . $doc['number']) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('portal/document_body', ['doc' => $doc, 'customerName' => $customerName, 'context' => 'pdf']) ?>
</body>
</html>
