<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/httpdocs'])
    ->name('*.php')
    ->notPath('vendor');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // ── PSR-12 + Symfony base ────────────────────────────────────────
        '@PSR12'                               => true,
        '@Symfony'                             => true,

        // ── Strict types ─────────────────────────────────────────────────
        'declare_strict_types'                 => true,
        'strict_param'                         => true,
        'strict_comparison'                    => true,

        // ── Imports ──────────────────────────────────────────────────────
        'ordered_imports'                      => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                    => true,
        'global_namespace_import'              => [
            'import_classes'   => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // ── Arrays ───────────────────────────────────────────────────────
        'array_syntax'                         => ['syntax' => 'short'],
        'trailing_comma_in_multiline'          => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_comma_in_singleline'      => true,

        // ── Operators + style ────────────────────────────────────────────
        'binary_operator_spaces'               => ['default' => 'single_space'],
        'concat_space'                         => ['spacing' => 'one'],
        'not_operator_with_space'              => false,
        'object_operator_without_whitespace'   => true,
        'ternary_operator_spaces'              => true,

        // ── PHPDoc ───────────────────────────────────────────────────────
        'phpdoc_align'                         => ['align' => 'vertical'],
        'phpdoc_separation'                    => false,
        'phpdoc_to_comment'                    => false,
        'phpdoc_var_without_name'              => true,
        'no_superfluous_phpdoc_tags'           => ['remove_inheritdoc' => false],

        // ── Blank lines ──────────────────────────────────────────────────
        'no_extra_blank_lines'                 => ['tokens' => ['curly_brace_block', 'extra', 'parenthesis_brace_block', 'square_brace_block', 'throw', 'use']],

        // ── Misc ─────────────────────────────────────────────────────────
        'single_quote'                         => true,
        'yoda_style'                           => false,
        'cast_spaces'                          => ['space' => 'single'],
    ])
    ->setFinder($finder);
