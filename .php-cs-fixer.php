<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->name('\\.php$')
    ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setRules([
        '@DoctrineAnnotation'       => true,
        '@PSR2'                     => true,
        '@PSR12'                    => true,
        '@PHP81Migration'           => true,
        '@PHP80Migration:risky'     => true,
        '@PHPUnit84Migration:risky' => true,
        'array_syntax'              => ['syntax' => 'short'],
        'braces'                    => ['allow_single_line_closure' => true],
        'cast_spaces'               => ['space' => 'none'],
        'compact_nullable_typehint' => true,
        'concat_space'              => ['spacing' => 'one'],
        'declare_equal_normalize'   => ['space' => 'none'],
        'declare_strict_types'      => true,
        'binary_operator_spaces'    => [
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'dir_constant'                               => true,
        'function_typehint_space'                    => true,
        'single_line_comment_style'                  => true,
        'lowercase_cast'                             => true,
        'method_argument_space'                      => ['on_multiline' => 'ensure_fully_multiline'],
        'modernize_types_casting'                    => true,
        'native_function_casing'                     => true,
        'new_with_braces'                            => true,
        'no_alias_functions'                         => true,
        'no_blank_lines_after_phpdoc'                => true,
        'linebreak_after_opening_tag'                => true,
        'no_empty_phpdoc'                            => true,
        'no_empty_statement'                         => true,
        'no_extra_blank_lines'                       => true,
        'no_leading_import_slash'                    => true,
        'no_leading_namespace_whitespace'            => true,
        'no_null_property_initialization'            => true,
        'no_short_bool_cast'                         => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_superfluous_elseif'                      => true,
        'no_trailing_comma_in_singleline'            => true,
        'no_unneeded_control_parentheses'            => true,
        'no_unused_imports'                          => true,
        'no_useless_else'                            => true,
        'no_whitespace_in_blank_line'                => true,
        'ordered_imports'                            => true,
        'phpdoc_no_access'                           => true,
        'phpdoc_no_empty_return'                     => true,
        'phpdoc_no_package'                          => true,
        'phpdoc_scalar'                              => true,
        'phpdoc_trim'                                => true,
        'phpdoc_types'                               => true,
        'phpdoc_types_order'                         => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'return_type_declaration'                    => ['space_before' => 'none'],
        'single_quote'                               => true,
        'single_trait_insert_per_statement'          => true,
        'whitespace_after_comma_in_array'            => true,
    ])
    ->setFinder($finder);
