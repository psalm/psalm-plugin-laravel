<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

return (new Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3x0' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        // import
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder(
        (new Finder())
            ->in([
                __DIR__ . '/bin',
                __DIR__ . '/src',
                __DIR__ . '/tests',
                __DIR__ . '/tools',
            ])
            // Laravel writes packages.php/services.php into fixture bootstrap/cache dirs when
            // subprocess tests boot the app; those generated files must never be style-checked.
            ->notPath('#Fixtures/.+/bootstrap/cache/#')
            ->in(__DIR__ . '/bin/ci')
            ->append([
                __FILE__,
            ]),
    )
    ->setParallelConfig(ParallelConfigFactory::detect());
