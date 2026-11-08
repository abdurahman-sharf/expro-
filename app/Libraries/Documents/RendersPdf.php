<?php

namespace App\Libraries\Documents;

use App\Libraries\Pdf_lib;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

trait RendersPdf
{
    /**
     * A PDF file as the answer to a request: shown in the browser under a name made of the title and the
     * number of the document, or a plain refusal with a message when the file cannot be made.
     *
     * @param array{format?: array|string, rtl?: bool, title?: string} $options
     */
    protected function pdfResponse(ResponseInterface $response, string $html, string $title, string $number, array $options = []): ResponseInterface
    {
        try {
            $pdf = (new Pdf_lib())->render($html, ['title' => $title] + $options);
        } catch (Throwable $exception) {
            log_message('error', 'PDF generation failed: ' . $exception->getMessage());

            return $response->setStatusCode(500)->setJSON(['success' => false, 'message' => lang('Documents.pdf_failed')]);
        }

        $name = preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', '-', trim($title . ' ' . $number)) . '.pdf';

        return $response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . rawurlencode($name) . '"')
            ->setBody($pdf);
    }
}
