<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\DocumentError;
use App\Libraries\Documents\DocumentException;
use App\Libraries\Documents\QrImage;
use App\Libraries\Documents\SimplifiedInvoiceQr;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class QrImageTest extends CIUnitTestCase
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private QrImage $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->image = new QrImage();
    }

    private function payload(string $seller = 'Bobs Records'): string
    {
        return (new SimplifiedInvoiceQr())->encode($seller, '310122393500003', '2022-04-25T15:30:00Z', '1000.00', '150.00');
    }

    /**
     * @param list<list<bool>> $modules
     */
    private function errorCorrectionBitsOf(array $modules): int
    {
        $last = count($modules) - 1;
        $bits = 0;

        for ($i = 0; $i < 15; $i++) {
            $column = match (true) {
                $i < 8  => $last - $i,
                $i < 9  => 15 - $i,
                default => 14 - $i,
            };

            $bits |= ((int) $modules[8][$column]) << $i;
        }

        return (($bits ^ 0b101010000010010) >> 13) & 0b11;
    }

    public function testPng_ProducesAPngFile(): void
    {
        $png = $this->image->png($this->payload());

        $this->assertStringStartsWith(self::PNG_SIGNATURE, $png);
        $this->assertSame('IEND', substr($png, -8, 4));
    }

    public function testPng_ProducesTheSameBytesForTheSamePayload(): void
    {
        $this->assertSame($this->image->png($this->payload()), (new QrImage())->png($this->payload()));
    }

    public function testPng_ProducesAnotherPictureForAnotherPayload(): void
    {
        $this->assertNotSame($this->image->png($this->payload()), $this->image->png($this->payload('Alice Records')));
    }

    public function testPng_DrawsEveryModuleAsASquareOfWholePixelsAndLeavesTheQuietZoneWhite(): void
    {
        $payload = $this->payload();
        $modules = $this->image->modules($payload);
        $picture = imagecreatefromstring($this->image->png($payload));
        $across  = count($modules) + 2 * QrImage::QUIET_ZONE;
        $pixels  = imagesx($picture) / $across;

        $this->assertNotFalse($picture);
        $this->assertSame(imagesx($picture), imagesy($picture));
        $this->assertSame(0.0, fmod($pixels, 1));
        $this->assertSame(2, imagecolorstotal($picture));

        for ($y = 0; $y < $across; $y++) {
            for ($x = 0; $x < $across; $x++) {
                $expectedDark = $modules[$y - QrImage::QUIET_ZONE][$x - QrImage::QUIET_ZONE] ?? false;

                foreach ([[0, 0], [$pixels - 1, $pixels - 1], [(int) ($pixels / 2), (int) ($pixels / 2)]] as [$dx, $dy]) {
                    $color = imagecolorsforindex($picture, imagecolorat($picture, (int) ($x * $pixels + $dx), (int) ($y * $pixels + $dy)));

                    $this->assertSame($expectedDark ? 0 : 255, $color['red'], 'module ' . $x . ',' . $y);
                }
            }
        }
    }

    public function testPngDataUri_CarriesThePngAsBase64(): void
    {
        $uri = $this->image->pngDataUri($this->payload());

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame($this->image->png($this->payload()), base64_decode(substr($uri, strlen('data:image/png;base64,')), true));
    }

    public function testModules_DrawsTheThreeFinderPatternsAndTheTimingLines(): void
    {
        $modules = $this->image->modules($this->payload());
        $last    = count($modules) - 1;
        $finder  = static fn (int $row, int $column): bool => max(abs($row - 3), abs($column - 3)) !== 2;

        foreach ([[0, 0], [0, $last - 6], [$last - 6, 0]] as [$top, $left]) {
            for ($row = 0; $row < 7; $row++) {
                for ($column = 0; $column < 7; $column++) {
                    $this->assertSame($finder($row, $column), $modules[$top + $row][$left + $column], 'finder at ' . $top . ',' . $left);
                }
            }
        }

        for ($i = 8; $i <= $last - 8; $i++) {
            $this->assertSame($i % 2 === 0, $modules[6][$i], 'row timing ' . $i);
            $this->assertSame($i % 2 === 0, $modules[$i][6], 'column timing ' . $i);
        }

        $this->assertTrue($modules[$last - 7][8], 'the dark module');
    }

    public function testModules_HasTheSideOfAVersionOfTheSymbol(): void
    {
        foreach ([$this->payload(), $this->payload(str_repeat('a', 120)), 'x'] as $payload) {
            $side = count($this->image->modules($payload));

            $this->assertSame(0, ($side - 17) % 4);
            $this->assertGreaterThanOrEqual(21, $side);
            $this->assertCount($side, $this->image->modules($payload)[0]);
        }
    }

    public function testModules_UsesErrorCorrectionOfLevelM(): void
    {
        foreach ([$this->payload(), $this->payload(str_repeat('b', 200)), 'x'] as $payload) {
            $this->assertSame(0b00, $this->errorCorrectionBitsOf($this->image->modules($payload)));
        }
    }

    public function testModules_GivesTheSameSymbolForAFixedPayload(): void
    {
        $text = implode('', array_map(static fn (array $row): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row)), $this->image->modules($this->payload())));

        $this->assertSame(41 * 41, strlen($text));
        $this->assertSame('06b343e5f94a685ed82514159d18ba6b57ce8dff2fcaea4701e84ef190316f5a', hash('sha256', $text));
    }

    public function testModules_RefusesAnEmptyPayload(): void
    {
        foreach (['', '   ', "\n"] as $payload) {
            try {
                $this->image->modules($payload);
                $this->fail('The empty text was drawn.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModules_RefusesAPayloadThatCannotFitInASymbol(): void
    {
        try {
            $this->image->modules(str_repeat('a', 3000));
            $this->fail('The text was drawn.');
        } catch (DocumentException $exception) {
            $this->assertSame(DocumentError::QrValueTooLong, $exception->error);
        }
    }

    public function testPng_RefusesAnEmptyPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->image->png('');
    }

    public function testSizeMm_KeepsThePreferredSizeWhileEveryModuleIsHalfAMillimetreOrMore(): void
    {
        $payload = $this->payload();
        $across  = $this->image->modulesAcross($payload);

        $this->assertLessThanOrEqual(QrImage::SIZE_THERMAL_80_MM / QrImage::MINIMUM_MODULE_MM, $across);
        $this->assertSame(QrImage::SIZE_A4_MM, $this->image->sizeMm($payload, QrImage::SIZE_A4_MM));
        $this->assertSame(QrImage::SIZE_THERMAL_80_MM, $this->image->sizeMm($payload, QrImage::SIZE_THERMAL_80_MM));
    }

    public function testSizeMm_GrowsTheSideSoNoModuleFallsUnderHalfAMillimetre(): void
    {
        $payload = $this->payload('شركة الأول برو للتجارة');
        $across  = $this->image->modulesAcross($payload);
        $size    = $this->image->sizeMm($payload, QrImage::SIZE_THERMAL_58_MM);

        $this->assertGreaterThan(QrImage::SIZE_THERMAL_58_MM, $size);
        $this->assertEqualsWithDelta(QrImage::MINIMUM_MODULE_MM, $size / $across, 0.0001);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function sellerLengthProvider(): array
    {
        return ['short' => [4], 'usual' => [22], 'long' => [50], 'longest' => [127]];
    }

    #[DataProvider('sellerLengthProvider')]
    public function testSizeMm_HoldsAModuleOfHalfAMillimetreOnTheA4SizeForTheNameOfAnySellerUpToTheLimit(int $letters): void
    {
        $payload = $this->payload(str_repeat('ش', $letters));
        $size    = $this->image->sizeMm($payload, QrImage::SIZE_A4_MM);

        $this->assertGreaterThanOrEqual($this->image->modulesAcross($payload) * QrImage::MINIMUM_MODULE_MM, $size);
        $this->assertLessThanOrEqual(60.0, $size);
    }
}
