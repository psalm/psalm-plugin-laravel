<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Expr;
use Psalm\StatementsSource;
use Psalm\Type\Union;

/**
 * Tiny accessors for `getCallArgs()` lists from {@see \Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent}
 * and {@see \Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent}.
 *
 * Use the helper when a handler reads a single argument node or its inferred
 * type by index. When a handler passes the whole arg list down to a resolver,
 * or branches on more than missing-vs-present (e.g. count-aware empty checks
 * with literal-null handling), inline the access — the helper has no answer
 * for those shapes and would only obscure the intent.
 *
 * The class name shadows {@see \PhpParser\Node\Arg}. When a handler also imports
 * the PhpParser symbol (e.g. as a parameter type), import this helper aliased
 * as `ArgUtil` to keep both names usable.
 *
 * @internal
 */
final class Arg
{
    /**
     * Return the AST expression of the argument at $index, or null if the
     * index is out of range.
     *
     * @param list<\PhpParser\Node\Arg> $args
     * @psalm-mutation-free
     */
    public static function nodeAt(array $args, int $index): ?Expr
    {
        return $args[$index]->value ?? null;
    }

    /**
     * Return the inferred Psalm type of the argument at $index, or null if
     * the argument is missing or its type is unknown.
     *
     * Both null cases collapse intentionally: callers that need to distinguish
     * "no arg" from "arg of unknown type" should fetch the node via
     * {@see self::nodeAt()} and call `getNodeTypeProvider()->getType()` themselves.
     *
     * @param list<\PhpParser\Node\Arg> $args
     */
    public static function typeAt(array $args, StatementsSource $source, int $index): ?Union
    {
        $node = $args[$index]->value ?? null;

        if ($node === null) {
            return null;
        }

        return $source->getNodeTypeProvider()->getType($node);
    }
}
