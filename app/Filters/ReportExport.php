<?php

namespace App\Filters;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\Export\ExportFormat;
use App\Libraries\Accounting\Export\ExportOptions;
use App\Libraries\Accounting\Export\ReportExporter;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Mpdf\MpdfException;

/**
 * Gives a report in another format than CSV. The address of the file is handled by the controller of the
 * CSV of the report, with its permission, its branches and its refusals, and what that writes is turned into
 * the format asked: so a file in any format holds exactly the rows the same filters give in CSV. The
 * arguments are the format and the key of the title of the report.
 */
class ReportExport implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $format = ExportFormat::tryFrom($arguments[0] ?? '');

        if ($format === null || $format === ExportFormat::Csv || $response->getStatusCode() !== ResponseInterface::HTTP_OK || ! str_starts_with($response->getHeaderLine('Content-Type'), 'text/csv')) {
            return $response;
        }

        $query = $request instanceof IncomingRequest ? $request->getGet() : [];

        try {
            $body = (new ReportExporter())->convert($format, (string) $response->getBody(), lang($arguments[1] ?? ''), ExportOptions::fromQuery(is_array($query) ? $query : []));
        } catch (AccountingException $refusal) {
            return $this->refused($response, AccountingMessage::of($refusal));
        } catch (MpdfException $failure) {
            log_message('error', 'Report PDF export failed: ' . $failure->getMessage());

            return $this->refused($response, lang('Bootstrap_tables.export_failed'), ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $response
            ->setContentType($format->contentType(), '')
            ->setHeader('Content-Disposition', (string) preg_replace('/\.csv"$/', '.' . $format->value . '"', $response->getHeaderLine('Content-Disposition')))
            ->setBody($body);
    }

    private function refused(ResponseInterface $response, string $message, int $status = ResponseInterface::HTTP_UNPROCESSABLE_ENTITY): ResponseInterface
    {
        return $response
            ->removeHeader('Content-Disposition')
            ->setStatusCode($status)
            ->setContentType('text/plain', 'UTF-8')
            ->setBody($message);
    }
}
