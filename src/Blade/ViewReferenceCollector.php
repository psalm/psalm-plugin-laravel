<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Finds the compiled template references that inherit the including template's whole scope.
 *
 * @internal
 */
final class ViewReferenceCollector
{
    private ?Parser $parser = null;


    /** @return list<Stmt>|null null when the source does not parse */
    private function parse(string $php): ?array
    {
        $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();

        try {
            return \array_values($this->parser->parse($php) ?? []);
        } catch (\Throwable) {
            return null;
        }
    }


    /**
     * Finds the subset of compiled template references that inherit the including template's whole
     * scope, so a variable one of them reads is a variable the caller's data key feeds.
     *
     * Membership is decided by the data argument: `@includeIsolated` and `@each` compile to the
     * same `$__env->` methods but pass no parent scope, and must not launder a read.
     *
     * @return array{0: list<string>, 1: bool} view names, and whether one could not be resolved
     */
    public function collectDataIncludes(string $php): array
    {
        $stmts = $this->parse($php);

        if ($stmts === null) {
            return [[], true];
        }

        $names = [];
        $dynamic = false;

        foreach ((new NodeFinder())->findInstanceOf($stmts, MethodCall::class) as $call) {
            if (!$call->name instanceof Identifier || !$call->var instanceof Variable || $call->var->name !== '__env') {
                continue;
            }

            $method = \strtolower($call->name->toString());

            if (!$this->passesWholeScope($call->args)) {
                continue;
            }

            match ($method) {
                // @include, @includeIf, @extends, and the aliased-include directives.
                'make' => $this->applyLiteral($call->args, 0, $names, $dynamic),
                // @includeFirst / @extendsFirst take a list of candidate names, any of which renders.
                'first' => $this->applyLiteralList($call->args, $names, $dynamic),
                // @includeWhen / @includeUnless put the condition first.
                'renderwhen', 'renderunless' => $this->applyLiteral($call->args, 1, $names, $dynamic),
                default => null,
            };
        }

        return [$this->names($names), $dynamic];
    }

    /**
     * Whether one of the call's arguments is the `array_diff_key(get_defined_vars(), ['__data' => 1,
     * '__path' => 1])` that every scope-passing Blade directive compiles its data argument to. The
     * position shifts with the directive's own optional data array (`@include('x', [...])` pushes it
     * into `$mergeData`), so the whole argument list is scanned rather than one pinned index.
     *
     * @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args
     *
     * @psalm-mutation-free
     */
    private function passesWholeScope(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Arg
                || !$arg->value instanceof FuncCall
                || !$arg->value->name instanceof Name
                || \strtolower($arg->value->name->toString()) !== 'array_diff_key'
            ) {
                continue;
            }

            $inner = $arg->value->args[0] ?? null;

            if ($inner instanceof Arg
                && $inner->value instanceof FuncCall
                && $inner->value->name instanceof Name
                && \strtolower($inner->value->name->toString()) === 'get_defined_vars'
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param array<array-key, Arg|VariadicPlaceholder|ArgPlaceholder> $args
     * @param array<string, true>           $names
     *
     * @psalm-external-mutation-free
     */
    private function applyLiteral(array $args, int $position, array &$names, bool &$dynamic): void
    {
        $arg = $this->findArg($args, $position);

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
     *
     * @psalm-external-mutation-free
     */
    private function applyLiteralList(array $args, array &$names, bool &$dynamic): void
    {
        $arg = $this->findArg($args, 0);

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
    private function findArg(array $args, int $position): ?Arg
    {
        $positionsReliable = true;

        foreach ($args as $index => $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if ($arg->name instanceof \PhpParser\Node\Identifier) {
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

    /**
     * Names are collected as array KEYS to dedupe them, and PHP casts a numeric-string key to int:
     * `123.blade.php` is a legal view, and `@include('123')` would otherwise hand an int to every
     * `string`-typed consumer downstream and throw under strict_types.
     *
     * @param array<array-key, true> $names
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private function names(array $names): array
    {
        return \array_map(\strval(...), \array_keys($names));
    }
}
