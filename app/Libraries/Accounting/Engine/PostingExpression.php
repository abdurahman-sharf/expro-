<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;

/**
 * The amount of a line of a posting rule, worked out from the figures of the document. An expression
 * is made of numbers, the names of figures in self::NAMES, the four operations and parentheses, and
 * nothing else: a name that is not in the list or a character that is not one of these is refused
 * before anything is worked out, so no text of a rule is ever run.
 */
final class PostingExpression
{
    public const NAMES = [
        'amount', 'gross', 'net', 'tax', 'total', 'cost', 'paid', 'shipping', 'shipping_tax', 'adjustment',
        'discount', 'rounding', 'fx_diff', 'dep', 'nbv', 'value', 'proceeds', 'accumulated',
    ];
    private const SCALE  = 12;
    private const RESULT = 4;

    /**
     * @param array<string, string> $figures The figures of the document by name, as decimal strings.
     *
     * @return numeric-string
     *
     * @throws PostingException
     */
    public static function evaluate(string $expression, array $figures): string
    {
        $tokens   = self::tokens($expression);
        $position = 0;
        $value    = self::sum($tokens, $position, $figures, $expression);

        if ($position !== count($tokens)) {
            self::refuse($expression, 'it does not end where an expression can end');
        }

        return self::round($value);
    }

    /**
     * @throws PostingException
     */
    public static function assertValid(string $expression): void
    {
        $tokens   = self::tokens($expression);
        $position = 0;

        self::sum($tokens, $position, array_fill_keys(self::NAMES, '1'), $expression);

        if ($position !== count($tokens)) {
            self::refuse($expression, 'it does not end where an expression can end');
        }
    }

    /**
     * @return list<string>
     *
     * @throws PostingException
     */
    private static function tokens(string $expression): array
    {
        if (trim($expression) === '' || preg_match('/^[\sa-z_0-9.+\-*\/()]*$/', $expression) !== 1) {
            self::refuse($expression, 'it holds a character an expression cannot have');
        }

        preg_match_all('/\d+(?:\.\d+)?|[a-z_][a-z0-9_]*|[+\-*\/()]|\S/', $expression, $matches);

        foreach ($matches[0] as $token) {
            if (preg_match('/^[a-z_]/', $token) === 1 && ! in_array($token, self::NAMES, true)) {
                self::refuse($expression, "{$token} is not the name of a figure");
            }
        }

        return $matches[0];
    }

    /**
     * @param list<string>          $tokens
     * @param array<string, string> $figures
     */
    private static function sum(array $tokens, int &$position, array $figures, string $expression): string
    {
        $value = self::product($tokens, $position, $figures, $expression);

        while (in_array($tokens[$position] ?? null, ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $right    = self::product($tokens, $position, $figures, $expression);
            $value    = $operator === '+' ? bcadd($value, $right, self::SCALE) : bcsub($value, $right, self::SCALE);
        }

        return $value;
    }

    /**
     * @param list<string>          $tokens
     * @param array<string, string> $figures
     */
    private static function product(array $tokens, int &$position, array $figures, string $expression): string
    {
        $value = self::factor($tokens, $position, $figures, $expression);

        while (in_array($tokens[$position] ?? null, ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $right    = self::factor($tokens, $position, $figures, $expression);

            if ($operator === '/' && bccomp($right, '0', self::SCALE) === 0) {
                self::refuse($expression, 'it divides by nothing');
            }

            $value = $operator === '*' ? bcmul($value, $right, self::SCALE) : bcdiv($value, $right, self::SCALE);
        }

        return $value;
    }

    /**
     * @param list<string>          $tokens
     * @param array<string, string> $figures
     */
    private static function factor(array $tokens, int &$position, array $figures, string $expression): string
    {
        $token = $tokens[$position++] ?? null;

        if ($token === '-') {
            return bcsub('0', self::factor($tokens, $position, $figures, $expression), self::SCALE);
        }

        if ($token === '(') {
            $value = self::sum($tokens, $position, $figures, $expression);

            if (($tokens[$position++] ?? null) !== ')') {
                self::refuse($expression, 'a parenthesis is not closed');
            }

            return $value;
        }

        if ($token !== null && preg_match('/^\d+(?:\.\d+)?$/', $token) === 1) {
            return $token;
        }

        if ($token !== null && in_array($token, self::NAMES, true)) {
            if (! array_key_exists($token, $figures) || ! is_numeric($figures[$token])) {
                self::refuse($expression, "the document has no figure {$token}");
            }

            return (string) $figures[$token];
        }

        self::refuse($expression, 'a number, a figure or a parenthesis is missing');
    }

    private static function round(string $value): string
    {
        $half = bcdiv('5', bcpow('10', (string) (self::RESULT + 1)), self::RESULT + 1);

        return str_starts_with($value, '-')
            ? bcsub($value, $half, self::RESULT)
            : bcadd($value, $half, self::RESULT);
    }

    /**
     * @throws PostingException
     */
    private static function refuse(string $expression, string $why): never
    {
        throw new PostingException(PostingError::RuleInvalid, "The amount {$expression} of a posting rule cannot be worked out: {$why}.", ['code' => $expression]);
    }
}
