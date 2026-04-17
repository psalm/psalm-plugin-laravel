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
    ->withSkipPath('tests/Unit/Handlers/Eloquent/Schema/migrations')
    // UNSAFE_METHOD_IDS uses intentionally-lowercased "class::method" strings so we can
    // match strtolower(getDeclaringMethodId()) without runtime normalization. Rector
    // keeps "upgrading" them to \Class::class concatenation, which silently breaks the
    // lookup: ::class preserves source-code casing, so a mis-cased FQN produces a key
    // that never matches the lowercase method id. (::class IS valid in const arrays on
    // PHP 8.3+; the problem is the casing, not the const-context.)
    ->withSkipPath('src/Handlers/Rules/OctaneIncompatibleBindingHandler.php')
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withSets([PHPUnitSetList::PHPUNIT_120])
    ->withPreparedSets(deadCode: true, codingStyle: true, typeDeclarations: true, codeQuality: true, phpunitCodeQuality: true)
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class,
        SimplifyUselessVariableRector::class,
        NullableCompareToNullRector::class,
        EncapsedStringsToSprintfRector::class,
        SplitDoubleAssignRector::class,
        StringClassNameToClassConstantRector::class, // analyzed classes are not always auto-loaded
    ]);
