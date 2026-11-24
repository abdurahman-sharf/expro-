<?php
/**
 * @var string               $inner   The view that draws the document.
 * @var array<string, mixed> $data    What it is given.
 * @var string               $pdfUrl
 * @var string               $backUrl
 * @var string               $backLabel
 * @var string               $backIcon
 */
?>

<?= view('partial/header') ?>

<?= view('partial/print_receipt', ['print_after_sale' => false, 'selected_printer' => 'invoice_printer']) ?>

<?= view('partial/document_actions', ['actions' => array_values(array_filter([
    ['id' => 'show_print_button', 'label' => lang('Common.print'), 'icon' => 'bi-printer', 'onclick' => 'printdoc();', 'class' => 'btn-primary'],
    $pdfUrl === '' ? null : ['id' => 'show_pdf_button', 'label' => lang('Sales.pdf'), 'icon' => 'bi-file-earmark-pdf', 'href' => $pdfUrl, 'target' => '_blank'],
    ['id' => 'show_back_button', 'label' => $backLabel, 'icon' => $backIcon, 'href' => $backUrl],
]))]) ?>

<?= view($inner, $data + ['context' => 'screen']) ?>

<?= view('partial/footer') ?>
