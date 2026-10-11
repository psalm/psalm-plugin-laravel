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
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
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
    private const OPAQUE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'func_get_args', 'func_get_arg',
        'debug_backtrace', 'debug_print_backtrace', 'call_user_func', 'call_user_func_array'];

    public static function resolve(Codebase $codebase): void
    {
        /** @var array<string, array<array-key, true>|null> $methods class::method => keys, memoized */
        $methods = [];

        foreach (ContractRegistry::composerSources() as $viewName => $sources) {
            // Written explicitly: a key set an earlier run recorded must not outlive the proof for it.
            if ($sources === null) {
                ContractRegistry::registerComposedKeys((string) $viewName, null);

                continue;
            }

            $keys = [];

            foreach ($sources as [$class, $method]) {
                $id = "{$class}::{$method}";

                if (!\array_key_exists($id, $methods)) {
                    $methods[$id] = self::keysOfMethod($codebase, $class, $method);
                }

                if ($methods[$id] === null) {
                    ContractRegistry::registerComposedKeys((string) $viewName, null);

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

                if (!$constructor instanceof Stmt\ClassMethod || $constructor->stmts === null || self::hasOpaqueNode($constructor->stmts) || self::hasOpaqueDefault($constructor)) {
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

        $resolved = ClassMethodResolver::resolve($codebase, $declaring);

        // Laravel calls the method from outside the class, so anything but a public one lands in
        // `__call()`. An `as protected` adaptation lives on the using class, not on the trait's method.
        if ($resolved === null) {
            return null;
        }

        $visibility = $codebase->classlike_storage_provider->get($appearing->fq_class_name)->trait_visibility_map[$method]
            ?? $resolved['methodStorage']->visibility;

        return $visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC ? $resolved['classMethod'] : null;
    }

    /** A default value is evaluated when the container builds the composer, and `new` runs a constructor. */
    private static function hasOpaqueDefault(Stmt\ClassMethod $method): bool
    {
        foreach ($method->params as $parameter) {
            if ($parameter->default instanceof Expr && (new NodeFinder())->findFirstInstanceOf($parameter->default, Expr\New_::class) instanceof Node) {
                return true;
            }
        }

        return false;
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

        if (self::hasOpaqueNode($stmts) || self::hasOpaqueDefault($method)) {
            return null;
        }

        $finder = new NodeFinder();

        $keys = [];
        $receivers = [];

        $expressions = [];

        foreach ($finder->findInstanceOf($stmts, Stmt\Expression::class) as $statement) {
            $expressions[] = $statement->expr;
        }

        // Laravel ignores what a composer returns (`Factory::callComposer()` discards the dispatch
        // result, ManagesEvents.php:177-180; `Dispatcher::invokeListeners()` only stops on `false`,
        // Dispatcher.php:326-343), so `return $view->with(...)` is a plain call. A `return` nested in a
        // closure or branch is not accepted: the view would leave through it.
        foreach ($stmts as $statement) {
            if ($statement instanceof Stmt\Return_ && $statement->expr instanceof Expr) {
                $expressions[] = $statement->expr;
            }
        }

        foreach ($expressions as $node) {
            $links = [];

            while ($node instanceof Expr\MethodCall) {
                $links[] = $node;
                $node = $node->var;
            }

            if ($links === [] || !$node instanceof Expr\Variable || $node->name !== $view) {
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

        foreach ([...$finder->findInstanceOf($stmts, Expr\MethodCall::class), ...$finder->findInstanceOf($stmts, Expr\NullsafeMethodCall::class)] as $call) {
            if ($call->var instanceof Expr\Variable && $call->var->name === $view && self::isReadOnly($call)) {
                $receivers[\spl_object_id($call->var)] = true;
            }
        }

        // Every other mention of the view is somewhere the walker cannot follow.
        foreach ($finder->findInstanceOf($stmts, Expr\Variable::class) as $variable) {
            if ($variable->name === $view && !isset($receivers[\spl_object_id($variable)])) {
                return null;
            }
        }

        return $keys;
    }

    /** The accessors that read the view without touching its data (`getData()` and `getFactory()` do not qualify). */
    private static function isReadOnly(Expr\MethodCall|Expr\NullsafeMethodCall $call): bool
    {
        return !$call->isFirstClassCallable() && $call->name instanceof Identifier && \in_array($call->name->toLowerString(), ['name', 'getname', 'getpath'], true);
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
            $node instanceof Expr\FuncCall => !$node->name instanceof Name || self::isOpaqueFunction($node->name),
            // `View::share()` adds keys at render time that no `with()` shows (Factory::make merges them).
            // So may a constructor this never reads (`parent::__construct()`, `Other::__construct()`).
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall, $node instanceof Expr\StaticCall
                => !$node->name instanceof Identifier || \in_array($node->name->toLowerString(), ['share', '__construct'], true),
            $node instanceof Expr\Variable => !\is_string($node->name),
            // An included or evaluated file runs in this method's scope, `$view` and all.
            $node instanceof Expr\Include_, $node instanceof Expr\Eval_ => true,
            default => false,
        };
    }

    /** Matches the imported or namespaced spelling too: `use function func_get_args as args`. */
    private static function isOpaqueFunction(Name $name): bool
    {
        foreach (['resolvedName', 'namespacedName'] as $attribute) {
            /** @psalm-var mixed $resolved */
            $resolved = $name->getAttribute($attribute);

            if ($resolved instanceof Name) {
                $resolved = $resolved->toString();
            }

            if (\is_string($resolved) && \in_array(\strtolower(\ltrim($resolved, '\\')), self::OPAQUE_FUNCTIONS, true)) {
                return true;
            }
        }

        return \in_array($name->toLowerString(), self::OPAQUE_FUNCTIONS, true);
    }

    /**
     * @return array<array-key, true>|null
     */
    private static function withKeys(Expr\MethodCall $call): ?array
    {
        $args = $call->args;

        if (!$call->name instanceof Identifier || $call->name->toLowerString() !== 'with' || $call->isFirstClassCallable() || $args === [] || \count($args) > 2) {
            return null;
        }

        $key = null;
        $position = 0;

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }

            // `with(key: ..., value: ...)` in either order; any other parameter name is not `with()`'s.
            if ($arg->name instanceof Identifier) {
                if ($arg->name->name === 'key') {
                    $key = $arg->value;
                } elseif ($arg->name->name !== 'value') {
                    return null;
                }

                continue;
            }

            if ($position++ === 0) {
                $key = $arg->value;
            }
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
