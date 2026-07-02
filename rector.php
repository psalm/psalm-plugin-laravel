<?php

use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\CodeQuality\Rector\MethodCall\AssertEmptyNullableObjectToAssertInstanceofRector;
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
    // The vendor-style macro fixture (PR #991 + PR #994) deliberately exercises
    // closures without native return types or docblock `@return` so the
    // AST-scan + body-inference paths are the only sources of narrowing.
    // Rector's `typeDeclarations` set keeps "fixing" them back to declared
    // return types, which silently turns the body-inference assertions into
    // native-return-type assertions and the feature stops being tested.
    ->withSkipPath('tests/Type/macro-fixtures-vendor-style.php')
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
        // Rewrites `assertNull($nullableObj)` to `assertNotInstanceOf(Obj::class, $nullableObj)`.
        // Too permissive for unit tests: `assertNotInstanceOf` passes for null, scalars, arrays,
        // and unrelated objects — losing the precise null-only intent.
        AssertEmptyNullableObjectToAssertInstanceofRector::class,
        // Rewrites `isset($obj->prop)` to `property_exists($obj, 'prop') || $obj->prop === null`.
        // Runtime-equivalent for SimpleXMLElement's magic __isset (verified), but Psalm's type
        // narrower has special-case support for isset() on a dynamic property and none for the
        // property_exists()-based form, so the rewrite silently turns every such guard back into
        // a MixedAssignment/MixedPropertyFetch source. Every isset($xml->child) check in
        // PluginConfig/Diagnostics that detects a missing <experimental>/<plugins> element
        // depends on staying isset().
        IssetOnPropertyObjectToPropertyExistsRector::class,
    ]);
