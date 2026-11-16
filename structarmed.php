<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\CodeQualityPreset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;

return Architecture::define()
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Plugin', 'src/Plugin.php')
    ->layer('Blade', 'src/Blade/')
    ->layer('Bootstrap', 'src/Bootstrap/')
    ->layer('Cli', 'src/Cli/')
    ->layer('Config', 'src/Config/')
    ->layer('Handlers', 'src/Handlers/')
    ->layer('Internal', 'src/Internal/')
    ->layer('Issues', 'src/Issues/')
    ->layer('Stubs', 'src/Stubs/')
    ->ruleset([
        // leaves
        'Config' => [],
        'Issues' => [],

        'Bootstrap' => ['Internal'],
        'Stubs' => ['Internal'],
        'Internal' => ['Blade', 'Bootstrap', 'Config', 'Issues'],
        'Blade' => ['Handlers', 'Internal', 'Issues'],
        'Handlers' => ['Blade', 'Bootstrap', 'Internal', 'Issues', 'Stubs'],

        // entry points
        'Plugin' => ['+Handlers', 'Config'],
        'Cli' => ['Plugin', 'Blade', 'Bootstrap', 'Config'],
    ])
    ->skip([
        '*/tests/Unit/*/Fixtures/*',
        '*/tests/Unit/Handlers/Eloquent/Schema/migrations/*',

        // multiple classes in single file
        Psr4Preset::CLASSES_MUST_MATCH_COMPOSER => [
            '*/tests/Unit/Handlers/Rules/NoEnvOutsideConfigHandlerTest.php',
            '*/tests/Unit/Handlers/Views/MissingViewHandlerTest.php',
            '*/tests/Unit/PluginInvocationStateTest.php',
            '*/tests/Unit/Providers/MacroRegistryTest.php',
        ],

        // closures that must stay bindable: `bindDirective()` rebinds its handler to the compiler,
        // and the provider fixture exists to register closures bound to the provider instance
        CodeQualityPreset::ANONYMOUS_FUNCTIONS_MUST_BE_STATIC => [
            '*/tests/Application',
            '*/tests/Unit/Blade/CompilerEnvironmentTest.php',
            '*/tests/Unit/Blade/ProviderBoundDirectives.php',
        ],
    ]);
