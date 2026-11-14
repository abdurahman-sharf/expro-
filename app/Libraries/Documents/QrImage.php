<?php

namespace App\Libraries\Documents;

use InvalidArgumentException;
use Mpdf\QrCode\QrCode;
use Mpdf\QrCode\QrCodeException;
use RuntimeException;

/**
 * The picture of the code of the electronic invoice as the printed documents carry it: the text of the code
 * drawn as a QR symbol with error correction of level M and a quiet zone of four modules, as a PNG with two
 * colours made with GD. Every module is a whole number of pixels, so the picture stays sharp at any size and
 * the same text always gives the same bytes. The size on paper is left to the template, which asks sizeMm()
 * for it: no module is ever smaller than half a millimetre, below which common scanners stop reading.
 */
final class QrImage
{
    public const QUIET_ZONE            = 4;
    public const MINIMUM_MODULE_MM     = 0.5;
    public const SIZE_A4_MM            = 34.0;
    public const SIZE_THERMAL_80_MM    = 30.0;
    public const SIZE_THERMAL_58_MM    = 30.0;
    private const PIXELS_PER_MODULE    = 10;
    private const BORDER_OF_THE_ENGINE = 4;

    /**
     * The modules of the symbol without its quiet zone; true is a dark one.
     *
     * @return list<list<bool>>
     */
    public function modules(string $payload): array
    {
        if (trim($payload) === '') {
            throw new InvalidArgumentException('The text of a code cannot be empty.');
        }

        try {
            $code = new QrCode($payload, QrCode::ERROR_CORRECTION_MEDIUM);
        } catch (QrCodeException $exception) {
            throw new DocumentException(DocumentError::QrValueTooLong, context: ['bytes' => strlen($payload)], previous: $exception);
        }

        $cells  = $code->getFinal();
        $width  = $code->getQrSize();
        $symbol = $width - 2 * self::BORDER_OF_THE_ENGINE;
        $rows   = [];

        for ($y = 0; $y < $symbol; $y++) {
            $row = [];

            for ($x = 0; $x < $symbol; $x++) {
                $row[] = (bool) $cells[($x + self::BORDER_OF_THE_ENGINE) + ($y + self::BORDER_OF_THE_ENGINE) * $width + 1];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function modulesAcross(string $payload): int
    {
        return count($this->modules($payload)) + 2 * self::QUIET_ZONE;
    }

    public function sizeMm(string $payload, float $preferredMm): float
    {
        return max($preferredMm, $this->modulesAcross($payload) * self::MINIMUM_MODULE_MM);
    }

    public function png(string $payload): string
    {
        $modules = $this->modules($payload);
        $pixels  = (count($modules) + 2 * self::QUIET_ZONE) * self::PIXELS_PER_MODULE;
        $image   = imagecreate($pixels, $pixels);

        if ($image === false) {
            throw new RuntimeException('The picture of the code cannot be made.');
        }

        imagecolorallocate($image, 255, 255, 255);
        $dark = imagecolorallocate($image, 0, 0, 0);

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $left = ($x + self::QUIET_ZONE) * self::PIXELS_PER_MODULE;
                    $top  = ($y + self::QUIET_ZONE) * self::PIXELS_PER_MODULE;
                    imagefilledrectangle($image, $left, $top, $left + self::PIXELS_PER_MODULE - 1, $top + self::PIXELS_PER_MODULE - 1, $dark);
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    public function pngDataUri(string $payload): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($payload));
    }
}
