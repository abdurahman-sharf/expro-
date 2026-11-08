<?php

namespace App\Libraries\Accounting\Export;

use InvalidArgumentException;

/**
 * Writes the zip container of a workbook in plain PHP, so the Excel files do not depend on the zip
 * extension, which the image of the application does not carry: each file is stored deflated when that is
 * smaller and as it is otherwise, with its checksum and sizes written up front.
 */
final class ZipWriter
{
    private const LOCAL_HEADER   = 0x04034B50;
    private const CENTRAL_HEADER = 0x02014B50;
    private const END_OF_ARCHIVE = 0x06054B50;
    private const VERSION        = 20;
    private const METHOD_STORED  = 0;
    private const METHOD_DEFLATE = 8;
    private const DOS_TIME       = 0;
    private const DOS_DATE       = 0x0021;
    private const MAX_ENTRIES    = 65_535;

    private string $files     = '';
    private string $directory = '';
    private int $count        = 0;

    public function add(string $name, string $content): void
    {
        if ($name === '' || strlen($name) > 0xFFFF || preg_match('/[^\x20-\x7E]/', $name) === 1) {
            throw new InvalidArgumentException('The name of a file in the zip is plain text of up to 65535 characters.');
        }

        if ($this->count >= self::MAX_ENTRIES || strlen($this->files) + strlen($content) > 0xFFFF_FFFF) {
            throw new InvalidArgumentException('The zip holds more than the format allows without extensions.');
        }

        $deflated = gzdeflate($content);
        $method   = $deflated !== false && strlen($deflated) < strlen($content) ? self::METHOD_DEFLATE : self::METHOD_STORED;
        $data     = $method === self::METHOD_DEFLATE ? (string) $deflated : $content;
        $checksum = crc32($content);
        $offset   = strlen($this->files);

        $this->files .= pack('VvvvvvVVVvv', self::LOCAL_HEADER, self::VERSION, 0, $method, self::DOS_TIME, self::DOS_DATE, $checksum, strlen($data), strlen($content), strlen($name), 0) . $name . $data;

        $this->directory .= pack('VvvvvvvVVVvvvvvVV', self::CENTRAL_HEADER, self::VERSION, self::VERSION, 0, $method, self::DOS_TIME, self::DOS_DATE, $checksum, strlen($data), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;

        $this->count++;
    }

    public function render(): string
    {
        return $this->files . $this->directory . pack('VvvvvVVv', self::END_OF_ARCHIVE, 0, 0, $this->count, $this->count, strlen($this->directory), strlen($this->files), 0);
    }
}
