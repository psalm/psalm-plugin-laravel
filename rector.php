<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths(['bin', 'src', 'tests', 'tools'])
    ->withRootFiles()
    ->withSkip([
        'tests/Unit/Handlers/Eloquent/Schema/migrations',
        '*/Fixtures/*', // Analysis inputs, not plugin source: their exact shape is what the tests assert (e.g. reordered named arguments, untyped closures), and some hold gitignored files Laravel writes when a fixture app boots.
        'bin/ci', // Standalone procedural scripts, not part of the analysed plugin surface. Rector rewrote every `report(...)` call here down to a single argument while leaving the 5-parameter declaration untouched, so the auto-fix workflow committed a file that fatals on load.
        'tests/Type/macro-fixtures-vendor-style.php', // The vendor-style macro fixture (PR #991 + PR #994) deliberately exercises closures without native return types or docblock `@return` so the AST-scan + body-inference paths are the only sources of narrowing.
        \Rector\PHPUnit\CodeQuality\Rector\FuncCall\AssertFuncCallToPHPUnitAssertRector::class => ['tests/*'], //  Rewrites `assert($x instanceof Foo)` inside test classes to `Assert::assertInstanceOf(...)`,  the two are not equivalent
        \Rector\PHPUnit\CodeQuality\Rector\MethodCall\AssertEmptyNullableObjectToAssertInstanceofRector::class => ['tests/*'],
        // The `insteadof` / alias fixtures need one `use A, B { ... }` block. Splitting it into separate `use` statements makes the trait methods collide (fatal) and drops the precedence under test.
        \Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector::class => [
            'tests/Application/app/Services/PrecedenceMacroableService.php',
            'tests/Application/app/Services/AliasedMacroableService.php',
        ],
        \Rector\DeadCode\Rector\ClassMethod\RemoveParentDelegatingClassMethodRector::class => ['tests/*'], // GetKeyOverrideModel
        // Rewrites `=== null` on a Psalm-typed nullable into `instanceof` a Psalm\Internal class, which silently
        // evaluates false (disabling the handler) once Psalm renames that class.
        \Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector::class => [
            'src/Handlers/Ai/LlmOutputTaintHandler.php',
            'src/Handlers/Eloquent/WhereColumnTaintHandler.php',
            'src/Handlers/Rules/TimingUnsafeComparisonHandler.php',
            'src/Handlers/Taint/NamedArgumentTaintHandler.php',
        ],
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withDowngradeSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true, phpunitCodeQuality: true)
    ->withSkip([
        StringClassNameToClassConstantRector::class, // analyzed classes are not always auto-loaded
        UseClassKeywordForClassNameResolutionRector::class, // analyzed classes are not always auto-loaded
    ]);
