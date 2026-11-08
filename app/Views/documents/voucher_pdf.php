<?php

use App\Libraries\Documents\VoucherDocument;

/**
 * @var VoucherDocument $voucher
 */
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($voucher->title . ' ' . $voucher->number) ?></title>
    <style><?= file_get_contents(FCPATH . 'assets/css/document.css') ?></style>
</head>
<body>
<?= view('documents/voucher', ['voucher' => $voucher, 'context' => 'pdf']) ?>
</body>
</html>
