<?php

use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\Switch_\RemoveDuplicatedCaseInSwitchRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withSkipPath('tests/Unit/Handlers/Eloquent/Schema/migrations')
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withSets([PHPUnitSetList::PHPUNIT_120])
    ->withPreparedSets(deadCode: true, codingStyle: true, typeDeclarations: true, codeQuality: true, phpunitCodeQuality: true)
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class,
        SimplifyUselessVariableRector::class,
        NullableCompareToNullRector::class,
        EncapsedStringsToSprintfRector::class,
        SplitDoubleAssignRector::class,
        StringClassNameToClassConstantRector::class, // analyzed classes are not always auto-loaded
        // The switch in SchemaAggregator intentionally has cases before `default:` that share
        // the same body — the `default:` must remain at the bottom to catch unknown Blueprint methods
        // without making subsequent cases (float, drop, etc.) unreachable
        RemoveDuplicatedCaseInSwitchRector::class => ['src/Handlers/Eloquent/Schema/SchemaAggregator.php'],
    ]);
