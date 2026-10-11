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
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;
use Psalm\Storage\ClassLikeStorage;

/**
 * Turns the composer sources {@see ContractRegistry::composerSources()} holds into the data keys
 * each view's composers provably pass, by reading the `compose()` / `create()` body as written
 * instead of running it.
 *
 * The one invariant: a key set may be too BIG, never too small. A key missing from it reports the
 * user's correct call site as missing that variable, while a key wrongly added only silences a
 * report. So a body is read only when every use of the view parameter is a `with()` call with
 * literal keys; anything else (the view handed to a method, stored, reassigned or poked through
 * `__set`/`__call`, a non-literal key, a `share()`, `compact()`) makes the whole set unknown.
 *
 * Runs once after the codebase is populated and before Psalm forks, so every worker inherits the
 * result.
 *
 * @internal
 */
final class ComposerKeyResolver
{
    /** Read data by name, or reach the arguments without naming them. */
    private const OPAQUE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'func_get_args', 'func_get_arg'];

    public static function resolve(Codebase $codebase): void
    {
        /** @var array<string, array<array-key, true>|null> $methods class::method => keys, memoized */
        $methods = [];

        foreach (ContractRegistry::composerSources() as $viewName => $sources) {
            if ($sources === null) {
                continue;
            }

            $keys = [];

            foreach ($sources as [$class, $method]) {
                $id = "{$class}::{$method}";

                if (!\array_key_exists($id, $methods)) {
                    $methods[$id] = self::keysOfMethod($codebase, $class, $method);
                }

                if ($methods[$id] === null) {
                    continue 2;
                }

                $keys += $methods[$id];
            }

            ContractRegistry::registerComposedKeys((string) $viewName, $keys);
        }
    }

    /**
     * @return array<array-key, true>|null
     */
    private static function keysOfMethod(Codebase $codebase, string $class, string $method): ?array
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($class);

            // The container builds the composer before it calls the method, so a constructor that
            // shares data (or reaches the view scope another way) adds keys no `with()` shows.
            if (isset($storage->declaring_method_ids['__construct'])) {
                $constructor = self::declaredMethod($codebase, $storage, '__construct');

                if (!$constructor instanceof Stmt\ClassMethod || $constructor->stmts === null || self::hasOpaqueNode($constructor->stmts)) {
                    return null;
                }
            }

            $resolved = self::declaredMethod($codebase, $storage, \strtolower($method));

            return $resolved instanceof Stmt\ClassMethod ? self::keysIn($resolved) : null;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }
    }

    private static function declaredMethod(Codebase $codebase, ClassLikeStorage $storage, string $method): ?Stmt\ClassMethod
    {
        $declaring = $storage->declaring_method_ids[$method] ?? null;
        $appearing = $storage->appearing_method_ids[$method] ?? null;

        if (!$declaring instanceof MethodIdentifier || !$appearing instanceof MethodIdentifier) {
            return null;
        }

        // Psalm ignores `insteadof` (vimeo/psalm#12113), so with two traits declaring the method
        // it may name the body that loses. The declaring name, not the requested one: an `as`
        // alias is absent from the trait method maps.
        if ($codebase->classlike_storage_provider->get($declaring->fq_class_name)->is_trait
            && RelationMethodParser::countTraitOwners(
                $codebase,
                $codebase->classlike_storage_provider->get($appearing->fq_class_name)->used_traits,
                $declaring->method_name,
            ) > 1
        ) {
            return null;
        }

        return ClassMethodResolver::resolve($codebase, $declaring)['classMethod'] ?? null;
    }

    /**
     * @return array<array-key, true>|null null when the body may pass keys this cannot name
     */
    public static function keysIn(Stmt\ClassMethod $method): ?array
    {
        $stmts = $method->stmts;

        if ($stmts === null) {
            return null;
        }

        $parameter = $method->params[0] ?? null;
        $view = null;

        if ($parameter !== null) {
            if ($parameter->byRef || $parameter->variadic || !$parameter->var instanceof Expr\Variable || !\is_string($parameter->var->name)) {
                return null;
            }

            $view = $parameter->var->name;
        }

        if (self::hasOpaqueNode($stmts)) {
            return null;
        }

        $finder = new NodeFinder();

        $keys = [];
        $receivers = [];

        foreach ($finder->findInstanceOf($stmts, Stmt\Expression::class) as $statement) {
            $links = [];
            $node = $statement->expr;

            while ($node instanceof Expr\MethodCall) {
                $links[] = $node;
                $node = $node->var;
            }

            if ($links === [] || !$node instanceof Expr\Variable || $node->name !== $view) {
                continue;
            }

            foreach ($links as $link) {
                $linkKeys = self::withKeys($link);

                if ($linkKeys === null) {
                    return null;
                }

                $keys += $linkKeys;
            }

            $receivers[\spl_object_id($node)] = true;
        }

        // Every other mention of the view is somewhere the walker cannot follow.
        foreach ($finder->findInstanceOf($stmts, Expr\Variable::class) as $variable) {
            if ($variable->name === $view && !isset($receivers[\spl_object_id($variable)])) {
                return null;
            }
        }

        return $keys;
    }

    /**
     * @param array<array-key, Node> $stmts
     */
    private static function hasOpaqueNode(array $stmts): bool
    {
        return (new NodeFinder())->findFirst($stmts, self::isOpaque(...)) instanceof Node;
    }

    private static function isOpaque(Node $node): bool
    {
        return match (true) {
            $node instanceof Expr\FuncCall => $node->name instanceof Name
                && \in_array($node->name->toLowerString(), self::OPAQUE_FUNCTIONS, true),
            // `View::share()` adds keys at render time that no `with()` shows (Factory::make merges them).
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall, $node instanceof Expr\StaticCall
                => $node->name instanceof Identifier && $node->name->toLowerString() === 'share',
            $node instanceof Expr\Variable => !\is_string($node->name),
            // An included or evaluated file runs in this method's scope, `$view` and all.
            $node instanceof Expr\Include_, $node instanceof Expr\Eval_ => true,
            default => false,
        };
    }

    /**
     * @return array<array-key, true>|null
     */
    private static function withKeys(Expr\MethodCall $call): ?array
    {
        $args = $call->args;

        if (!$call->name instanceof Identifier || $call->name->toLowerString() !== 'with' || $args === [] || \count($args) > 2) {
            return null;
        }

        $key = null;

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
                return null;
            }

            $key ??= $arg->value;
        }

        if ($key instanceof String_) {
            return [$key->value => true];
        }

        if (!$key instanceof Expr\Array_) {
            return null;
        }

        $keys = [];

        foreach ($key->items as $item) {
            // A spread or list-style item has no literal key.
            if (!$item instanceof Expr\ArrayItem || !$item->key instanceof String_) {
                return null;
            }

            $keys[$item->key->value] = true;
        }

        return $keys;
    }
}
