<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingExpression;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PostingExpressionTest extends CIUnitTestCase
{
    private const FIGURES = ['net' => '100.0000', 'tax' => '15.0000', 'gross' => '-115.0000', 'cost' => '33.3333'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function expressionProvider(): array
    {
        return [
            'a figure'                       => ['net', '100.0000'],
            'a sum'                          => ['net + tax', '115.0000'],
            'the product before the sum'     => ['net + tax * 2', '130.0000'],
            'parentheses first'              => ['(net + tax) * 2', '230.0000'],
            'a division'                     => ['net / 3', '33.3333'],
            'half a unit rounds up'          => ['0.00005 + 0', '0.0001'],
            'half a unit below rounds down'  => ['0 - 0.00005', '-0.0001'],
            'a negative figure'              => ['gross', '-115.0000'],
            'unary minus'                    => ['-gross - tax', '100.0000'],
            'a number alone'                 => ['12.5', '12.5000'],
            'spaces do not count'            => [' net*3/ 3 ', '100.0000'],
            'many decimals kept until the end' => ['cost * 3', '99.9999'],
        ];
    }

    #[DataProvider('expressionProvider')]
    public function testEvaluate_AcceptsTheFourOperationsParenthesesNumbersAndNamedFigures(string $expression, string $expected): void
    {
        $this->assertSame($expected, PostingExpression::evaluate($expression, self::FIGURES));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'a name that is not a figure' => ['net + price'],
            'a function call'             => ['system(net)'],
            'a variable'                  => ['$net'],
            'a statement'                 => ['net; tax'],
            'a string'                    => ["'net'"],
            'a power'                     => ['net ** 2'],
            'a comparison'                => ['net > tax'],
            'an open parenthesis'         => ['(net + tax'],
            'a dangling operator'         => ['net +'],
            'two figures side by side'    => ['net tax'],
            'nothing'                     => ['  '],
            'a capital letter'            => ['NET'],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testEvaluate_RejectsAnythingButTheFourOperationsParenthesesNumbersAndNamedFigures(string $expression): void
    {
        $this->expectRefusal(static fn () => PostingExpression::evaluate($expression, self::FIGURES));
        $this->expectRefusal(static fn () => PostingExpression::assertValid($expression));
    }

    public function testEvaluate_RejectsADivisionByNothingAndAFigureTheDocumentDoesNotHave(): void
    {
        $this->expectRefusal(static fn () => PostingExpression::evaluate('net / (tax - 15)', self::FIGURES));
        $this->expectRefusal(static fn () => PostingExpression::evaluate('net + shipping', self::FIGURES));
    }

    public function testAssertValid_AcceptsEveryNamedFigureWithoutTheirValues(): void
    {
        PostingExpression::assertValid(implode(' + ', PostingExpression::NAMES));

        $this->addToAssertionCount(1);
    }

    private function expectRefusal(callable $action): void
    {
        try {
            $action();
            $this->fail('The expression was accepted.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RuleInvalid, $failure->error);
        }
    }
}
