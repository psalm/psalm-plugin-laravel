<?php

use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withSets([PHPUnitSetList::PHPUNIT_100])
    ->withPreparedSets(deadCode: true, codingStyle: true, typeDeclarations: true, codeQuality: true, phpunitCodeQuality: true)
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class,
        SimplifyUselessVariableRector::class,
        NullableCompareToNullRector::class,
        EncapsedStringsToSprintfRector::class,
        SplitDoubleAssignRector::class,
        StringClassNameToClassConstantRector::class, // analyzed classes are not always auto-loaded
    ]);
