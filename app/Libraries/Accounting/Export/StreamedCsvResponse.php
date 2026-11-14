<?php

namespace App\Libraries\Accounting\Export;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ExportsCsv;
use Closure;
use CodeIgniter\HTTP\DownloadResponse;

/**
 * A CSV file that is written to the reader as its rows are read, so that a file of any size costs the
 * memory of one row. Whoever needs the whole text, a test or a conversion to another format, gets it
 * from csv(), which stops at a limit of rows instead of holding a file that would be refused.
 */
final class StreamedCsvResponse extends DownloadResponse
{
    use ExportsCsv;

    private const FLUSH_EVERY = 500;
    private const BYTE_ORDER  = "\xEF\xBB\xBF";

    private ?string $text = null;

    /**
     * @param Closure(): iterable<list<string>> $rows
     */
    public function __construct(string $filename, private readonly Closure $rows)
    {
        parent::__construct($filename, false);

        $this->setContentType('text/csv', 'UTF-8');
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @throws AccountingException When the file holds more than $maxRows rows.
     */
    public function csv(?int $maxRows = null): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $handle = fopen('php://temp', 'r+b');
        $count  = 0;

        fwrite($handle, self::BYTE_ORDER);

        foreach (($this->rows)() as $cells) {
            if ($maxRows !== null && ++$count > $maxRows) {
                fclose($handle);

                throw new AccountingException(AccountingError::ReportFileTooLarge, 'The report is too large for the file asked for.', ['max' => $maxRows]);
            }

            $this->csvRow($handle, $cells);
        }

        $this->text = $this->csvText($handle);

        return $this->text;
    }

    public function getBody(): string
    {
        return $this->csv();
    }

    public function buildHeaders(): void
    {
    }

    public function send(): static
    {
        if (ENVIRONMENT !== 'testing') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->sendHeaders();
        $this->sendCookies();
        $this->sendBody();

        return $this;
    }

    public function sendBody(): static
    {
        $handle = fopen('php://output', 'wb');
        $count  = 0;

        fwrite($handle, self::BYTE_ORDER);

        foreach (($this->rows)() as $cells) {
            $this->csvRow($handle, $cells);

            if (++$count % self::FLUSH_EVERY === 0) {
                fflush($handle);
                flush();
            }
        }

        fclose($handle);

        return $this;
    }
}
