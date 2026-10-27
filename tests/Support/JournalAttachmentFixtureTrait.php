<?php

namespace Tests\Support;

use ZipArchive;

trait JournalAttachmentFixtureTrait
{
    /** @var list<string> */
    private array $uploadPaths = [];

    /**
     * A file as the upload leaves it in the temporary folder.
     */
    protected function upload(string $content, string $suffix = '.tmp'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'acc_up_');

        if ($suffix !== '.tmp') {
            rename($path, $path .= $suffix);
        }

        file_put_contents($path, $content);

        return $this->uploadPaths[] = $path;
    }

    protected function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    protected function pngContent(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    /**
     * The bytes of a small file of the given extension, or null when this machine cannot make one.
     */
    protected function contentOf(string $extension): ?string
    {
        switch ($extension) {
            case 'pdf':
                return $this->pdfContent();

            case 'png':
                return $this->pngContent();

            case 'txt':
                return "A plain note\n";

            case 'csv':
                return "account,amount\ncash,100.00\n";

            case 'jpg':
            case 'jpeg':
            case 'gif':
            case 'webp':
                $function = in_array($extension, ['jpg', 'jpeg'], true) ? 'imagejpeg' : 'image' . $extension;

                if (! function_exists($function)) {
                    return null;
                }

                $image = imagecreatetruecolor(8, 8);

                ob_start();
                $function($image);

                return (string) ob_get_clean();

            case 'xlsx':
            case 'docx':
                if (! class_exists(ZipArchive::class)) {
                    return null;
                }

                $path    = tempnam(sys_get_temp_dir(), 'acc_zip_');
                $archive = new ZipArchive();
                $archive->open($path, ZipArchive::OVERWRITE);
                $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
                $archive->addFromString($extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml', '<x/>');
                $archive->close();
                $content = (string) file_get_contents($path);
                unlink($path);

                return $content;
        }

        return null;
    }

    /**
     * Removes what the upload helpers made and the files the books stored for the rows that are
     * still in the table. The folder of the books is shared, so nothing else in it is touched.
     */
    protected function removeAttachmentFiles(?string $directory = null): void
    {
        foreach ($this->uploadPaths as $path) {
            @unlink($path);
        }

        $this->uploadPaths = [];
        $directory         = rtrim($directory ?? WRITEPATH . 'accounting_attachments', '/');

        foreach ($this->accountingDb()->table('accounting_journal_attachments')->select('stored_name')->get()->getResult() as $row) {
            @unlink($directory . '/' . $row->stored_name);
        }
    }

    /**
     * @return list<string>
     */
    protected function filesIn(string $directory): array
    {
        return array_values(array_filter(
            is_dir($directory) ? (scandir($directory) ?: []) : [],
            static fn (string $name): bool => ! in_array($name, ['.', '..', '.htaccess', 'index.html'], true),
        ));
    }
}
