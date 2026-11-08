<?php
/**
 * @var string $csvUrl The address of the CSV file of the report with the filters it was run with.
 * @var string $id     The id of the CSV button, which the ids of the other two buttons follow.
 */

use App\Libraries\Accounting\Export\ExportFormat;

?>
<a class="btn btn-outline-secondary btn-sm float-end" id="<?= esc($id, 'attr') ?>_xlsx" href="<?= esc(ExportFormat::Xlsx->addressOf($csvUrl), 'attr') ?>">
    <span class="bi bi-filetype-xlsx" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.xlsx') ?>
</a>
<a class="btn btn-outline-secondary btn-sm float-end" id="<?= esc($id, 'attr') ?>_pdf" href="<?= esc(ExportFormat::Pdf->addressOf($csvUrl), 'attr') ?>">
    <span class="bi bi-filetype-pdf" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.pdf') ?>
</a>
