<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * TestResponse::getBody() is the page after a DOM parser has read it and written it back, so markup inside
 * an attribute value comes out the same whether the page escaped it or not. A test that checks escaping
 * has to read the body the application sent, response()->getBody(). This test reads the tests themselves
 * and names every method that feeds markup to a page and reads the parsed body.
 */
final class EscapingAssertionsTest extends CIUnitTestCase
{
    private const MARKUP         = '/<(?:script|img|svg|iframe|b|i|u|s|q|d)\b[^>]*>|\bonerror\s*=|\bonload\s*=|\bonmouseover\s*=|&lt;[a-z]/i';
    private const PARSED_BODY    = '/(?<!response\(\))(?<!response)->getBody\(\)/';
    private const FUNCTION_START = '/^\s*(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(/m';

    /**
     * @return array<string, list<array{method: string, line: int}>>
     */
    private function methodsThatReadTheParsedBodyOfMarkup(): array
    {
        $offenders = [];
        $self      = realpath(__FILE__);
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $self) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'getBody()') || preg_match_all(self::FUNCTION_START, $source, $starts, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($starts[1] as $index => [$method, $offset]) {
                $end  = $starts[0][$index + 1][1] ?? strlen($source);
                $body = substr($source, $starts[0][$index][1], $end - $starts[0][$index][1]);
                $body = preg_split('/^\s*(?:private|public|protected)\s+const\b/m', $body)[0];

                if (preg_match(self::PARSED_BODY, $body) === 1 && preg_match(self::MARKUP, $body) === 1) {
                    $offenders[substr($file->getPathname(), strlen(__DIR__) + 1)][] = ['method' => $method, 'line' => substr_count($source, "\n", 0, $offset) + 1];
                }
            }
        }

        return $offenders;
    }

    public function testTests_RejectAMethodThatChecksMarkupAgainstTheParsedBodyOfAResponse(): void
    {
        $found = [];

        foreach ($this->methodsThatReadTheParsedBodyOfMarkup() as $file => $methods) {
            foreach ($methods as $method) {
                $found[] = "{$file}:{$method['line']} {$method['method']}";
            }
        }

        $this->assertSame([], $found, 'These tests feed markup to a page and read TestResponse::getBody(), which cannot see markup inside an attribute value. Read response()->getBody() instead.');
    }
}
