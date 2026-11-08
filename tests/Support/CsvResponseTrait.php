<?php

namespace Tests\Support;

use CodeIgniter\Test\TestResponse;

/**
 * Reads the CSV file a report gives back into its rows, after checking that it opens with the mark that
 * makes a spreadsheet read it as UTF-8.
 */
trait CsvResponseTrait
{
    /**
     * @return list<list<string>> The rows of the file, a blank line being an empty row.
     */
    private function csvRows(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : $row;
        }

        fclose($handle);

        return $rows;
    }
}
