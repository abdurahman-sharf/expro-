<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The files the application serves from public/assets that tools/build-assets.mjs writes out of the sources in
 * resources/assets: each is the text of its sources, cleaned and joined the way the build joins them, so that
 * running the build again changes nothing and a rule written only in a served file is found.
 *
 * @internal
 */
final class BuiltAssetsTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function bundles(): array
    {
        return [
            'application script'    => ['js', '.js', "\n;\n", 'js/app.js', 'the scripts of resources/assets/js'],
            'application styles'    => ['css', '.css', "\n\n", 'css/app.css', 'the styles of resources/assets/css'],
            'login page styles'     => ['pages', 'login.css', '', 'css/login.css', 'the login styles of resources/assets/pages'],
            'document print styles' => ['pages', 'document.css', '', 'css/document.css', 'the document styles of resources/assets/pages'],
        ];
    }

    #[DataProvider('bundles')]
    public function testBuild_ServesTheTextOfItsSourcesJoinedAsTheBuildJoinsThem(string $directory, string $suffix, string $separator, string $served, string $what): void
    {
        $this->assertSame($this->bundleOf($directory, $suffix, $separator), (string) file_get_contents(ROOTPATH . 'public/assets/' . $served), "public/assets/{$served} is not {$what}");
    }

    public function testBuild_JoinsTheSourcesInTheOrderOfTheirNames(): void
    {
        $names = $this->sourceNames('js', '.js');

        $this->assertGreaterThan(10, count($names));
        $this->assertSame('10-notify.js', $names[0]);
        $this->assertLessThan(array_search('46-stacked-tables.js', $names, true), array_search('45-accounting.js', $names, true));

        $served = (string) file_get_contents(ROOTPATH . 'public/assets/js/app.js');

        foreach ($names as $name) {
            $this->assertStringContainsString(trim((string) file_get_contents(ROOTPATH . 'resources/assets/js/' . $name)), $served, $name);
        }
    }

    public function testBuild_KeepsAnAmountOfAThermalReceiptOnOneLine(): void
    {
        $styles = (string) file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertMatchesRegularExpression('/\.rt-totals dd[^{]*\{[^}]*white-space: nowrap;/', $styles, 'an amount of the totals is cut in two on paper of 58 mm');
        $this->assertMatchesRegularExpression('/\.rt-calc > :last-child[^{]*\{[^}]*white-space: nowrap;/', $styles, 'the total of a line is cut in two on paper of 58 mm');
    }

    public function testBuild_ServesTheGuardThatLetsAFormOfTheRegisterGoOnce(): void
    {
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/app.js');

        $this->assertStringContainsString("form[data-single-shot]", $script, 'the guard of the forms that may be submitted once is not in the served script');
        $this->assertStringContainsString("a[data-single-shot]", $script, 'the guard of the links that may be followed once is not in the served script');
        $this->assertStringContainsString('pageshow', $script, 'a page restored from the history keeps its buttons locked');
    }

    private function bundleOf(string $directory, string $suffix, string $separator): string
    {
        $texts = [];

        foreach ($this->sourceNames($directory, $suffix) as $name) {
            $texts[] = trim($this->withoutSourceMaps((string) file_get_contents(ROOTPATH . 'resources/assets/' . $directory . '/' . $name)));
        }

        return implode($separator, $texts);
    }

    /**
     * @return list<string>
     */
    private function sourceNames(string $directory, string $suffix): array
    {
        $names = array_values(array_filter(scandir(ROOTPATH . 'resources/assets/' . $directory) ?: [], static fn (string $name): bool => str_ends_with($name, $suffix)));

        sort($names, SORT_STRING);

        return $names;
    }

    private function withoutSourceMaps(string $content): string
    {
        return (string) preg_replace(['/\n?\/\/# sourceMappingURL=.*$/m', '/\n?\/\*# sourceMappingURL=.*?\*\//'], '', $content);
    }
}
