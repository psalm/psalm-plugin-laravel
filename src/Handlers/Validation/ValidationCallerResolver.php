<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Walks a method call's caller type (`$caller->method(...)`) looking for a
 * TNamedObject that matches or extends `$baseClass`. Returns the first matching
 * class-string, or null when nothing in the caller's type union qualifies.
 *
 * Shared between {@see InlineValidateRulesCollector} (which wants a bool "is
 * the caller a Request?") and {@see ValidationTaintHandler} (which wants the
 * concrete class-string for further lookups). The walk handles exact-class
 * match, extends match, unpopulated classlikes, and invalid class-string
 * exceptions uniformly so both callers get the same semantics.
 *
 * @internal
 */
final class ValidationCallerResolver
{
    /**
     * @param class-string $baseClass
     * @return class-string|null
     */
    public static function resolveCallerClass(
        MethodCall $expr,
        StatementsSource $source,
        Codebase $codebase,
        string $baseClass,
    ): ?string {
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $callerType = $source->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            /** @var class-string $className */
            $className = $atomic->value;

            if ($className === $baseClass) {
                return $className;
            }

            try {
                if ($codebase->classExtends($className, $baseClass)) {
                    return $className;
                }
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}
