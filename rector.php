<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths(['bin', 'src', 'tests', 'tools'])
    ->withRootFiles()
    ->withSkip([
        'tests/Unit/Handlers/Eloquent/Schema/migrations',
        'bin/ci', // Standalone procedural scripts, not part of the analysed plugin surface. Rector rewrote every `report(...)` call here down to a single argument while leaving the 5-parameter declaration untouched, so the auto-fix workflow committed a file that fatals on load.
        'tests/Type/macro-fixtures-vendor-style.php', // The vendor-style macro fixture (PR #991 + PR #994) deliberately exercises closures without native return types or docblock `@return` so the AST-scan + body-inference paths are the only sources of narrowing.
        \Rector\PHPUnit\CodeQuality\Rector\FuncCall\AssertFuncCallToPHPUnitAssertRector::class => ['tests/*'], //  Rewrites `assert($x instanceof Foo)` inside test classes to `Assert::assertInstanceOf(...)`,  the two are not equivalent
        \Rector\PHPUnit\CodeQuality\Rector\MethodCall\AssertEmptyNullableObjectToAssertInstanceofRector::class => ['tests/*'],
        \Rector\DeadCode\Rector\ClassMethod\RemoveParentDelegatingClassMethodRector::class => ['tests/*'], // GetKeyOverrideModel
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withDowngradeSets(php82: true)
    ->withSets([PHPUnitSetList::PHPUNIT_120])
    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true, phpunitCodeQuality: true)
    ->withSkip([
        EncapsedStringsToSprintfRector::class,
        StringClassNameToClassConstantRector::class, // analyzed classes are not always auto-loaded
        UseClassKeywordForClassNameResolutionRector::class, // analyzed classes are not always auto-loaded
    ]);
