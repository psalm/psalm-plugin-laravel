<?php

use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\Closure\StaticClosureRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Strict\Rector\BooleanNot\BooleanInBooleanNotRuleFixerRector;
use Rector\Strict\Rector\If_\BooleanInIfConditionRuleFixerRector;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPhpSets(php81: true)
    ->withPreparedSets(deadCode: true, codingStyle: true, typeDeclarations: true)
    ->withSkip([
        ReadOnlyPropertyRector::class,
        ClosureToArrowFunctionRector::class,
        FirstClassCallableRector::class,
        NullToStrictStringFuncCallArgRector::class,
        BooleanInIfConditionRuleFixerRector::class,
        BooleanInBooleanNotRuleFixerRector::class,
        RemoveUnusedPrivateMethodRector::class,
        SimplifyUselessVariableRector::class,
        NullableCompareToNullRector::class,
        EncapsedStringsToSprintfRector::class,
        StaticClosureRector::class,
        SplitDoubleAssignRector::class,
    ]);
