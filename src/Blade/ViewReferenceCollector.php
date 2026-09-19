<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
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
 *  - {@see self::collectFromSource()} walks a COMPILED SHADOW at compile time. Its `$__env->make()` /
 *    `->first()` calls are Laravel's own compiled output of `@include`/`@extends`/`@includeFirst`,
 *    never arbitrary userland code, so a non-literal argument there really does mean an unresolvable
 *    template reference and turns the whole rule off.
 *  - {@see self::collect()} walks a plain project file. An arbitrary `$obj->make($x)` proves nothing
 *    about Blade, so only the unambiguous `view()` helper and `View::make()` facade forms are read
 *    there; over-collection (treating more calls as references than strictly proven) is the safe
 *    direction for an "unused" rule, but tripping the off-switch on an unrelated method call is not.
 *
 * Deliberately does not follow anonymous or class-based component tags, `@component`, `@each`,
 * `@includeWhen`, or `@includeUnless` (see `docs/blade.md` for the accepted v1 gap): a project using
 * any of them keeps the rule's true positives (`view()`/`@include`/`@extends`) but may see stale
 * false positives, one direction safer than an over-broad off-switch.
 */
final class ViewReferenceCollector
{
    /** @return array{0: list<string>, 1: bool} view names referenced, and whether an unresolvable reference was seen */
    public function collectFromSource(string $php): array
    {
        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($php);
        } catch (\Throwable) {
            return [[], false];
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

        // Only the compiled shadow's own `$__env->make()`/`->first()` calls are trusted this far;
        // see the class docblock for why a plain project file skips this branch entirely.
        if ($compiledShadow) {
            foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
                if (!$call->name instanceof Identifier) {
                    continue;
                }

                $method = \strtolower($call->name->toString());

                if ($method === 'make') {
                    $this->applyLiteral($call->args, 0, null, $names, $dynamic);
                } elseif ($method === 'first') {
                    $this->applyLiteralList($call->args, $names, $dynamic);
                }
            }
        }

        return [\array_keys($names), $dynamic];
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
