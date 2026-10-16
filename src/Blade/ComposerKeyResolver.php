<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;

/**
 * Reads the data keys each view's composers pass from the `compose()` / `create()` body as written.
 * A key set may be too big, never too small (a missing key reports a correct call site), so a body
 * counts only when every use of the view parameter is a literal-key `with()` or a read-only accessor.
 *
 * Runs after the codebase is populated and before Psalm forks, so every worker inherits the result.
 *
 * @internal
 */
final class ComposerKeyResolver
{
    public static function resolve(Codebase $codebase): void
    {
        /** @var array<string, array<array-key, true>|null> $methods class::method => keys, memoized */
        $methods = [];
        $composed = [];

        foreach (ContractRegistry::composerSources() as $viewName => $sources) {
            $composed[$viewName] = self::keysOf($codebase, $sources, $methods);
        }

        ContractRegistry::registerComposedKeys($composed);
    }

    /**
     * The keys the composers and creators of one view pass, null when one is unreadable.
     *
     * @param list<array{0: string, 1: string}>|null $sources {@see ContractRegistry::composerSources()}
     * @param array<string, array<array-key, true>|null> $methods class::method => keys, memoized
     *
     * @return array<array-key, true>|null
     */
    public static function keysOf(Codebase $codebase, ?array $sources, array &$methods = []): ?array
    {
        $keys = [];

        foreach ($sources ?? [] as [$class, $method]) {
            $id = "{$class}::{$method}";

            if (!\array_key_exists($id, $methods)) {
                $methods[$id] = self::keysOfMethod($codebase, $class, $method);
            }

            // One unreadable composer leaves the whole view unknown.
            if ($methods[$id] === null) {
                return null;
            }

            $keys += $methods[$id];
        }

        return $sources === null ? null : $keys;
    }

    /** @return array<array-key, true>|null */
    private static function keysOfMethod(Codebase $codebase, string $class, string $method): ?array
    {
        try {
            // Inherited and trait methods resolve to the body that declares them.
            $declaring = $codebase->classlike_storage_provider->get($class)->declaring_method_ids[$method] ?? null;
        } catch (\InvalidArgumentException) {
            return null;
        }

        $resolved = $declaring instanceof MethodIdentifier ? ClassMethodResolver::resolve($codebase, $declaring) : null;

        return $resolved === null ? null : self::keysIn($resolved['classMethod']);
    }

    /** @return array<array-key, true>|null null when the body may pass keys this cannot name */
    public static function keysIn(Stmt\ClassMethod $method): ?array
    {
        $finder = new NodeFinder();
        $stmts = $method->stmts;
        $parameter = $method->params[0]->var ?? null;
        $view = $parameter instanceof Expr\Variable ? $parameter->name : null;

        if ($stmts === null || $finder->findFirst($stmts, static fn(Node $node): bool => self::isOpaque($node, $view)) instanceof Node) {
            return null;
        }

        $keys = [];
        /** @var array<int, true> $receivers the `$view` nodes accounted for, by object id */
        $receivers = [];

        // `with()` returns the view, so only a chain whose result is discarded keeps it in reach.
        foreach ($finder->findInstanceOf($stmts, Stmt\Expression::class) as $statement) {
            $links = [];
            $node = $statement->expr;

            while ($node instanceof Expr\MethodCall) {
                $links[] = $node;
                $node = $node->var;
            }

            if (!$node instanceof Expr\Variable || $node->name !== $view) {
                continue;
            }

            // Past a read-only accessor the chain works on its result, not on the view.
            foreach (\array_reverse($links) as $link) {
                if (self::isReadOnly($link)) {
                    break;
                }

                $linkKeys = self::withKeys($link);

                if ($linkKeys === null) {
                    return null;
                }

                $keys += $linkKeys;
            }

            $receivers[\spl_object_id($node)] = true;
        }

        foreach ($finder->findInstanceOf($stmts, Expr\MethodCall::class) as $call) {
            if ($call->var instanceof Expr\Variable && $call->var->name === $view && self::isReadOnly($call)) {
                $receivers[\spl_object_id($call->var)] = true;
            }
        }

        // Any other mention of the view (passed on, assigned, captured) is out of the walker's reach.
        foreach ($finder->findInstanceOf($stmts, Expr\Variable::class) as $variable) {
            if ($variable->name === $view && !isset($receivers[\spl_object_id($variable)])) {
                return null;
            }
        }

        return $keys;
    }

    private static function isOpaque(Node $node, string|Expr|null $view): bool
    {
        return match (true) {
            // `share()` adds keys at render time that no `with()` shows.
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall, $node instanceof Expr\StaticCall
                => !$node->name instanceof Identifier || $node->name->toLowerString() === 'share',
            // A computed name may be any of these, which reach the view by name or position where the
            // occurrence check cannot see it.
            $node instanceof Expr\FuncCall => match ($node->name instanceof Name ? \ltrim($node->name->toLowerString(), '\\') : null) {
                null, 'extract', 'get_defined_vars', 'func_get_args', 'func_get_arg' => true,
                'compact' => self::compactsView($node, $view),
                default => false,
            },
            $node instanceof Expr\Variable => !\is_string($node->name),
            default => false,
        };
    }

    /**
     * `compact()` captures the view when it names it, or names something that is not a literal.
     *
     * @psalm-capabilities read-props
     */
    private static function compactsView(Expr\FuncCall $call, string|Expr|null $view): bool
    {
        foreach ($call->args as $arg) {
            $value = $arg instanceof Arg && !$arg->unpack ? $arg->value : null;

            foreach ($value instanceof Expr\Array_ ? $value->items : [$value] as $item) {
                $name = $item instanceof Expr\ArrayItem ? $item->value : $item;

                if (!$name instanceof String_ || $name->value === $view) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isReadOnly(Expr\MethodCall $call): bool
    {
        // A first-class callable (`$view->getName(...)`) keeps the view bound, so it can still be mutated.
        return !$call->isFirstClassCallable() && $call->name instanceof Identifier && \in_array($call->name->toLowerString(), ['name', 'getname', 'getpath'], true);
    }

    /** @return array<array-key, true>|null */
    private static function withKeys(Expr\MethodCall $call): ?array
    {
        $key = $call->args[0] ?? null;

        if (!$call->name instanceof Identifier || $call->name->toLowerString() !== 'with' || !$key instanceof Arg || $key->unpack || $key->name instanceof Identifier) {
            return null;
        }

        $keys = [];

        // `with('a', ...)` names one key, `with(['a' => ..., ...])` one per item.
        foreach ($key->value instanceof Expr\Array_ ? $key->value->items : [$key->value] as $item) {
            $literal = $item instanceof Expr\ArrayItem ? $item->key : $item;

            if (!$literal instanceof String_) {
                return null;
            }

            $keys[$literal->value] = true;
        }

        return $keys;
    }
}
