<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Walks a method call's caller type (`$caller->method(...)`) looking for a
 * TNamedObject that matches or extends `$baseClass`. Returns the first
 * matching class-string, or null when nothing in the caller's type union
 * qualifies.
 *
 * Used by handlers that need to confirm a method call lands on a particular
 * Laravel base class (Request, FormRequest, …) before applying narrowing or
 * taint logic. The walk handles exact-class match, extends match, unpopulated
 * classlikes, and invalid class-string exceptions uniformly so all callers
 * get the same semantics.
 *
 * Promoted from `Handlers\Validation\ValidationCallerResolver` once a second
 * (Routing) handler started consuming it — the routing namespace must not
 * depend on validation internals, so the helper now lives in `Util/`.
 *
 * @internal
 */
final class MethodCallerResolver
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
