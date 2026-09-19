<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Finds view-name references in an AST, name-only and type-free: it answers "does this file
 * mention view name X", never "does this call site actually render it" (that needs a
 * `StatementsSource`, which does not exist at `AfterCodebasePopulated`; see {@see ViewCallChain} for
 * the type-aware sibling used by call-site validation).
 *
 * Two callers, two literalness expectations:
 *  - {@see self::collectFromSource()} walks a COMPILED SHADOW at compile time. Its `$__env->`
 *    method calls (`make`, `first`, `renderEach`, `renderWhen`, `renderUnless`, `startComponent`)
 *    and component `X::resolve([...])` calls are Laravel's own compiled output of `@include`,
 *    `@extends`, `@includeFirst`, `@each`, `@includeWhen`/`@includeUnless`, `@component`, and
 *    component tags, never arbitrary userland code, so a non-literal argument there really does mean
 *    an unresolvable template reference and turns the whole rule off. The `$__env` receiver check is
 *    load-bearing: without it, an unrelated `$items->first(fn ...)` in a template would disable the
 *    rule project-wide.
 *  - {@see self::collect()} walks a plain project file. An arbitrary `$obj->make($x)` proves nothing
 *    about Blade, so only the unambiguous `view()` helper and `View::make()` facade forms are read
 *    there; over-collection (treating more calls as references than strictly proven) is the safe
 *    direction for an "unused" rule, but tripping the off-switch on an unrelated method call is not.
 */
final class ViewReferenceCollector
{
    /** @return array{0: list<string>, 1: bool} view names referenced, and whether an unresolvable reference was seen */
    public function collectFromSource(string $php): array
    {
        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($php);
        } catch (\Throwable) {
            // Unparseable, not empty: a shadow this plugin's own compiler produced but cannot itself
            // parse says nothing about what the template includes, so treating it as "no references"
            // would cascade into false UnusedView positives on everything it actually renders.
            return [[], true];
        }

        return $this->walk(\array_values($stmts ?? []), true);
    }

    /**
     * @param list<Stmt> $stmts
     *
     * @return array{0: list<string>, 1: bool}
     */
    public function collect(array $stmts): array
    {
        return $this->walk($stmts, false);
    }

    /**
     * @param list<Stmt> $stmts
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function walk(array $stmts, bool $compiledShadow): array
    {
        $finder = new NodeFinder();
        $names = [];
        $dynamic = false;

        foreach ($finder->findInstanceOf($stmts, FuncCall::class) as $call) {
            if ($call->name instanceof Name && \strtolower($call->name->toString()) === 'view') {
                $this->applyLiteral($call->args, 0, 'view', $names, $dynamic);
            }
        }

        foreach ($finder->findInstanceOf($stmts, StaticCall::class) as $call) {
            if (
                $call->name instanceof Identifier
                && \strtolower($call->name->toString()) === 'make'
                && $call->class instanceof Name
                && \strtolower($call->class->getLast()) === 'view'
            ) {
                $this->applyLiteral($call->args, 0, 'view', $names, $dynamic);
            }
        }

        // Only the compiled shadow's own `$__env->` calls are trusted this far; see the class
        // docblock for why a plain project file skips this branch entirely. Gated on the receiver
        // being the `$__env` variable specifically: an arbitrary `$items->first(fn ...)` in a
        // template is not Blade and must never disable the rule project-wide.
        if ($compiledShadow) {
            foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
                if (!$call->name instanceof Identifier || !$call->var instanceof Variable || $call->var->name !== '__env') {
                    continue;
                }

                $method = \strtolower($call->name->toString());

                if ($method === 'make' || $method === 'startcomponent') {
                    $this->applyLiteral($call->args, 0, null, $names, $dynamic);
                } elseif ($method === 'first') {
                    $this->applyLiteralList($call->args, $names, $dynamic);
                } elseif ($method === 'rendereach') {
                    // @each($view, $data, $iterVar, $empty): both the item view and the fallback are
                    // template references.
                    $this->applyLiteral($call->args, 0, null, $names, $dynamic);
                    $this->applyLiteral($call->args, 3, null, $names, $dynamic);
                } elseif ($method === 'renderwhen' || $method === 'renderunless') {
                    $this->applyLiteral($call->args, 1, null, $names, $dynamic);
                }
            }

            // A component tag (`<x-foo>`, `<x-dynamic-component>`) compiles its view name into an
            // `X::resolve([...])` call rather than a `$__env->` one; the `AnonymousComponent` case
            // carries a literal `'view'` key, everything else (including a dynamic component, whose
            // key is `'component'` instead) has none and falls through to `startComponent()`'s own
            // `$component->resolveView()` argument, never a literal, tripping the branch above.
            foreach ($finder->findInstanceOf($stmts, StaticCall::class) as $call) {
                if ($call->name instanceof Identifier && \strtolower($call->name->toString()) === 'resolve') {
                    $this->applyComponentView($call->args, $names, $dynamic);
                }
            }
        }

        return [\array_keys($names), $dynamic];
    }

    /**
     * @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args
     * @param array<string, true>                                     $names
     */
    private function applyComponentView(array $args, array &$names, bool &$dynamic): void
    {
        $arg = $this->findArg($args, 0, null);

        if (!$arg instanceof Arg) {
            return;
        }

        $array = $this->unwrapArray($arg->value);

        if (!$array instanceof Array_) {
            return;
        }

        foreach ($array->items as $item) {
            if ($item === null || !$item->key instanceof String_ || $item->key->value !== 'view') {
                continue;
            }

            if ($item->value instanceof String_) {
                $names[$item->value->value] = true;
            } else {
                $dynamic = true;
            }

            return;
        }
    }

    /**
     * `X::resolve()`'s single argument compiles as `[...] + (isset($attributes) ? ... : [])`
     * (`CompilesComponents::compileClassComponentOpening()`), so the literal array is the left
     * operand of a `+`, not the argument value itself.
     */
    private function unwrapArray(Node $expr): ?Array_
    {
        if ($expr instanceof Array_) {
            return $expr;
        }

        return $expr instanceof Plus ? $this->unwrapArray($expr->left) : null;
    }

    /**
     * @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args
     * @param array<string, true>           $names
     */
    private function applyLiteral(array $args, int $position, ?string $paramName, array &$names, bool &$dynamic): void
    {
        $arg = $this->findArg($args, $position, $paramName);

        if (!$arg instanceof Arg) {
            return;
        }

        if ($arg->value instanceof String_) {
            $names[$arg->value->value] = true;

            return;
        }

        $dynamic = true;
    }

    /**
     * @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args
     * @param array<string, true>           $names
     */
    private function applyLiteralList(array $args, array &$names, bool &$dynamic): void
    {
        $arg = $this->findArg($args, 0, null);

        if (!$arg instanceof Arg) {
            return;
        }

        if (!$arg->value instanceof Array_) {
            $dynamic = true;

            return;
        }

        foreach ($arg->value->items as $item) {
            if ($item === null || !$item->value instanceof String_) {
                $dynamic = true;

                continue;
            }

            $names[$item->value->value] = true;
        }
    }

    /** @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args */
    private function findArg(array $args, int $position, ?string $paramName): ?Arg
    {
        $positionsReliable = true;

        foreach ($args as $index => $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if ($arg->name instanceof \PhpParser\Node\Identifier) {
                if ($paramName !== null && $arg->name->toString() === $paramName) {
                    return $arg;
                }

                continue;
            }

            if ($arg->unpack) {
                // A spread shifts every later position, so nothing after it can be read positionally.
                $positionsReliable = false;

                continue;
            }

            if ($positionsReliable && $index === $position) {
                return $arg;
            }
        }

        return null;
    }
}
