<?php

declare(strict_types=1);

/**
 * This file is part of CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use Nexus\CsConfig\Fixer\Comment\NoCodeSeparatorCommentFixer;
use Nexus\CsConfig\FixerGenerator;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/public',
    ])
    ->exclude(['Views/errors/html'])
    ->append([
        __DIR__ . '/admin/starter/builds',
    ]);

$overrides = [
    // For updating to coding-standard
    'modernize_strpos'    => true,

    // Rules that change what the code does, not how it looks. The code inherited from OSPOS compares the strings the
    // database returns with numbers on purpose, so these are not applied by machine.
    'strict_comparison'                => false,
    'strict_param'                     => false,
    'logical_operators'                => false,
    'string_length_to_empty'           => false,
    'ternary_to_elvis_operator'        => false,
    'fopen_flags'                      => false,
    'no_trailing_whitespace_in_string' => false,
    'non_printable_character'          => false,
];

$options = [
    'cacheFile'    => 'build/.php-cs-fixer.no-header.cache',
    'finder'       => $finder,
    'customFixers' => FixerGenerator::create('vendor/nexusphp/cs-config/src/Fixer', 'Nexus\\CsConfig\\Fixer'),
    'customRules'  => [
        NoCodeSeparatorCommentFixer::name() => true,
    ],
];

return Factory::create(new CodeIgniter4(), $overrides, $options)->forProjects();
