<?php

use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
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
    ->withPreparedSets(deadCode: true)
    ->withSkip([
        ReadOnlyPropertyRector::class,
        ClosureToArrowFunctionRector::class,
        FirstClassCallableRector::class,
        NullToStrictStringFuncCallArgRector::class,
        BooleanInIfConditionRuleFixerRector::class,
        BooleanInBooleanNotRuleFixerRector::class,
        RemoveUnusedPrivateMethodRector::class,
        SimplifyUselessVariableRector::class,
    ]);
