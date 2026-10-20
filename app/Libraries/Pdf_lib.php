<?php

namespace App\Libraries;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Container\SimpleContainer;
use Mpdf\Http\ClientInterface;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Mpdf\PsrHttpMessageShim\Response;

class Pdf_lib
{
    public const FONT_FAMILY     = 'plexar';
    private const FONT_DIRECTORY = APPPATH . 'ThirdParty/IBMPlexSansArabic';

    /**
     * @param array{format?: array|string, rtl?: bool, margins?: array{top: float, right: float, bottom: float, left: float}, title?: string, footer?: string} $options
     *
     * @throws MpdfException
     */
    public function render(string $html, array $options = []): string
    {
        $engine = $this->createEngine($options);
        $engine->WriteHTML($html);

        return $engine->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param array{format?: array|string, rtl?: bool, margins?: array{top: float, right: float, bottom: float, left: float}, title?: string, footer?: string} $options
     *
     * @throws MpdfException
     */
    private function createEngine(array $options): Mpdf
    {
        $margins  = $options['margins'] ?? ['top' => 14, 'right' => 14, 'bottom' => 16, 'left' => 14];
        $defaults = (new ConfigVariables())->getDefaults();
        $fonts    = (new FontVariables())->getDefaults();

        $engine = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => $options['format'] ?? 'A4',
            'margin_top'    => $margins['top'],
            'margin_right'  => $margins['right'],
            'margin_bottom' => $margins['bottom'],
            'margin_left'   => $margins['left'],
            'tempDir'       => $this->tempDirectory(),
            'fontDir'       => array_merge($defaults['fontDir'], [self::FONT_DIRECTORY]),
            'fontdata'      => $fonts['fontdata'] + [
                self::FONT_FAMILY => [
                    'R'          => 'IBMPlexSansArabic-Regular.ttf',
                    'B'          => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL'     => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font'      => self::FONT_FAMILY,
            'default_font_size' => 10,
        ], new SimpleContainer(['httpClient' => $this->offlineClient()]));

        $engine->whitelistStreamWrappers = ['file'];
        $engine->SetDirectionality(($options['rtl'] ?? isRtl()) ? 'rtl' : 'ltr');
        $engine->SetTitle($options['title'] ?? '');
        $engine->SetCreator('الاول برو');

        if (isset($options['footer'])) {
            $engine->SetHTMLFooter($options['footer']);
        }

        return $engine;
    }

    private function tempDirectory(): string
    {
        $directory = WRITEPATH . 'cache/mpdf';

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function offlineClient(): ClientInterface
    {
        return new class () implements ClientInterface {
            public function sendRequest($request)
            {
                return new Response(403);
            }
        };
    }
}
