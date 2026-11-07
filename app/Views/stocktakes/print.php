<?php
/**
 * @var object       $stocktake
 * @var list<object> $lines
 * @var string       $number
 */
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Stock_documents.count_sheet') . ' ' . $number) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
</head>

<body class="p-4">
    <main>
        <h1 class="h4"><?= lang('Stock_documents.count_sheet') ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
        <p class="mb-1"><?= esc((string) $stocktake->location_name) ?> <bdi dir="ltr">(<?= esc((string) $stocktake->branch_code) ?>)</bdi></p>
        <p class="mb-3"><?= lang('Stock_documents.count_date') ?>: <bdi dir="ltr"><?= esc((string) $stocktake->count_date) ?></bdi> · <?= lang('Stock_documents.started_at') ?>: <bdi dir="ltr"><?= esc((string) $stocktake->started_at) ?></bdi></p>

        <table class="table table-bordered table-sm" id="count_sheet">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col"><?= lang('Stock_documents.item') ?></th>
                    <th scope="col" class="text-end"><?= lang('Stock_documents.system_quantity') ?></th>
                    <th scope="col"><?= lang('Stock_documents.counted_quantity') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $position => $line): ?>
                    <tr>
                        <td><?= $position + 1 ?></td>
                        <td><?= esc((string) $line->item_name) ?><?= (string) $line->item_number !== '' ? ' <small dir="ltr">' . esc((string) $line->item_number) . '</small>' : '' ?></td>
                        <td class="text-end"><bdi dir="ltr"><?= esc(rtrim(rtrim(bcadd((string) $line->system_quantity, '0', 3), '0'), '.')) ?></bdi></td>
                        <td style="min-width: 8rem">&nbsp;</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>

    <script>window.addEventListener('load', function() { window.print(); });</script>
</body>

</html>
