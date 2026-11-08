<?php

namespace Tests\Support;

/**
 * Reads the parts of a zip container by its own directory, in plain PHP and without the zip extension, so
 * what the tests say of a workbook does not depend on the code that wrote it or on a library of this machine.
 */
trait WorkbookPartsTrait
{
    /**
     * @return array<string, string> The content of each file of the zip by its name, checked against its checksum and size.
     */
    private function workbookParts(string $zip): array
    {
        $end = strrpos($zip, "PK\x05\x06");

        $this->assertNotFalse($end, 'The file ends with the record of the end of a zip');

        $record = unpack('vdisk/vstart/ventriesHere/ventries/Vsize/Voffset/vcomment', substr($zip, $end + 4, 18));

        $this->assertSame($record['entries'], $record['entriesHere']);

        $parts  = [];
        $cursor = $record['offset'];

        for ($entry = 0; $entry < $record['entries']; $entry++) {
            $this->assertSame("PK\x01\x02", substr($zip, $cursor, 4), 'A record of the directory');

            $central = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', substr($zip, $cursor + 4, 42));
            $name    = substr($zip, $cursor + 46, $central['nameLength']);
            $cursor += 46 + $central['nameLength'] + $central['extraLength'] + $central['commentLength'];

            $this->assertSame("PK\x03\x04", substr($zip, $central['offset'], 4), 'The header of the file ' . $name);

            $local = unpack('vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($zip, $central['offset'] + 4, 26));
            $data  = substr($zip, $central['offset'] + 30 + $local['nameLength'] + $local['extraLength'], $central['compressed']);

            $this->assertSame($name, substr($zip, $central['offset'] + 30, $local['nameLength']), 'The name agrees in both headers');
            $this->assertContains($central['method'], [0, 8], 'Stored or deflated');

            $content = $central['method'] === 8 ? gzinflate($data) : $data;

            $this->assertIsString($content, 'The file ' . $name . ' inflates');
            $this->assertSame($central['size'], strlen($content), 'The size of ' . $name);
            $this->assertSame($central['crc'], crc32($content), 'The checksum of ' . $name);

            $parts[$name] = $content;
        }

        $this->assertSame($end, $cursor, 'The directory ends where the end record starts');

        return $parts;
    }
}
