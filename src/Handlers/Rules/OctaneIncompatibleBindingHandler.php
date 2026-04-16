<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Contracts\Container\Container;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\OctaneIncompatibleBinding;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Flags request-scoped service resolutions inside shared-binding closures
 * (singleton, singletonIf, scoped, scopedIf).
 *
 * Under Laravel Octane, the application instance is reused across requests, so a
 * closure that resolves Request/Session/Auth during the first resolution keeps
 * that instance alive for every subsequent request.
 *
 * Handler is registered globally but short-circuits fast: it rejects 99%+ of
 * expressions on the MethodCall + method-name gates before touching the type
 * system.
 *
 * @see https://laravel.com/docs/octane#dependency-injection-and-octane
 * @see https://github.com/larastan/larastan/blob/3.x/src/Rules/OctaneCompatibilityRule.php
 */
final class OctaneIncompatibleBindingHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Container methods that register a single shared instance, reused across requests.
     * bind()/bindIf() are safe — they re-execute the closure per resolution.
     *
     * Keys are lowercased (PHP method names are case-insensitive).
     */
    private const UNSAFE_METHODS = [
        'singleton' => true,
        'singletonif' => true,
        'scoped' => true,
        'scopedif' => true,
    ];

    /**
     * Known container class names, used as a fast path before falling back to
     * is_a() (which triggers autoloading on every miss). Covers the vast majority
     * of real-world typings; custom subclasses fall through to the slow path.
     */
    private const KNOWN_CONTAINER_CLASSES = [
        \Illuminate\Container\Container::class => true,
        \Illuminate\Foundation\Application::class => true,
        \Illuminate\Contracts\Container\Container::class => true,
        \Illuminate\Contracts\Foundation\Application::class => true,
    ];

    /**
     * Core request-scoped Laravel services. Resolving any of these inside a shared
     * binding closure captures state from the first resolving request and leaks it
     * into every subsequent request under Octane.
     *
     * Entries mirror the class targets Laravel registers in
     * {@see \Illuminate\Foundation\Application::registerCoreContainerAliases()} —
     * both the Laravel class and the Symfony parent it also aliases, where applicable.
     *
     * Note: Illuminate\Routing\Route is intentionally NOT listed — it is a plain
     * data object, not a container-bound service. The "current route" is accessed
     * via the Router/facade, not by resolving Route::class.
     */
    private const REQUEST_SCOPED_CLASSES = [
        \Illuminate\Http\Request::class => true,
        \Symfony\Component\HttpFoundation\Request::class => true,
        \Illuminate\Session\Store::class => true,
        \Illuminate\Session\SessionManager::class => true,
        \Illuminate\Contracts\Session\Session::class => true,
        \Illuminate\Cookie\CookieJar::class => true,
        \Illuminate\Auth\AuthManager::class => true,
        \Illuminate\Contracts\Auth\Factory::class => true,
        \Illuminate\Contracts\Auth\Guard::class => true,
        \Illuminate\Contracts\Auth\Authenticatable::class => true,
    ];

    /**
     * String aliases for request-scoped services — the keys Laravel registers in
     * registerCoreContainerAliases(). Resolving via string alias is equivalent to
     * resolving via class-string.
     */
    private const REQUEST_SCOPED_ALIASES = [
        'request' => true,
        'auth' => true,
        'auth.driver' => true,
        'session' => true,
        'session.store' => true,
        'cookie' => true,
    ];

    /**
     * Container resolution methods that take an abstract name as their first arg.
     */
    private const RESOLVER_METHODS = [
        'make' => true,
        'makewith' => true,
        'get' => true,
        'resolve' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        // Fast reject on the first character — all UNSAFE_METHODS start with 's'.
        // This rejects the vast majority of method calls without allocating a
        // lowercase copy of the name string.
        $rawName = $expr->name->name;

        if ($rawName[0] !== 's' && $rawName[0] !== 'S') {
            return null;
        }

        if (!isset(self::UNSAFE_METHODS[\strtolower($rawName)])) {
            return null;
        }

        $args = $expr->getArgs();

        if (\count($args) < 2) {
            return null;
        }

        // Check closure shape BEFORE the type lookup: singleton(X::class, X::class)
        // is a common non-closure form, and a full getType() call is an order of
        // magnitude more expensive than an instanceof.
        $closure = $args[1]->value;

        if (!$closure instanceof Closure && !$closure instanceof ArrowFunction) {
            return null;
        }

        $callerType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!self::isContainerType($callerType)) {
            return null;
        }

        $containerParamName = self::getFirstParamName($closure);
        $methodName = $rawName;

        foreach (self::findResolutions($closure, $containerParamName) as [$abstract, $node]) {
            self::emitIssue($event, $methodName, $abstract, $node);
        }

        return null;
    }

    /**
     * The call target must be a Container-like object.
     * Application extends Container, so a single superclass check covers all cases.
     * Fast path for common class names avoids autoloading via is_a().
     *
     * @psalm-mutation-free
     */
    private static function isContainerType(?Union $type): bool
    {
        if (!$type instanceof \Psalm\Type\Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (isset(self::KNOWN_CONTAINER_CLASSES[$atomic->value])) {
                return true;
            }

            if (\is_a($atomic->value, Container::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of the closure's first parameter (Laravel passes the container here).
     * Returns null when the closure takes no parameters or the name is dynamic.
     *
     * @psalm-mutation-free
     */
    private static function getFirstParamName(Closure|ArrowFunction $closure): ?string
    {
        $params = $closure->getParams();

        if ($params === []) {
            return null;
        }

        $firstParam = $params[0];

        if (!$firstParam->var instanceof Variable || !\is_string($firstParam->var->name)) {
            return null;
        }

        return $firstParam->var->name;
    }

    /**
     * Yield each (abstract, node) violation found inside the closure body.
     *
     * We deliberately do not descend into nested closures/arrow functions: their
     * bodies define a separate scope that Psalm will analyze in its own
     * afterExpressionAnalysis event, so re-emitting here would cause duplicates.
     *
     * @return \Generator<int, array{class-string|string, Node}>
     */
    private static function findResolutions(Closure|ArrowFunction $closure, ?string $containerParamName): \Generator
    {
        $body = $closure instanceof Closure ? $closure->stmts : [$closure->expr];

        foreach (self::walkWithoutNestedClosures($body) as $node) {
            $violation = self::classifyResolution($node, $containerParamName);

            if ($violation !== null) {
                yield $violation;
            }
        }
    }

    /**
     * Match one of the patterns we care about:
     *   - $app->make(X::class)            / $app->make('request')
     *   - $app[X::class]                  / $app['request']
     *   - $this->app->make(X::class)      / $this->app['request']
     *   - app(X::class)                   / resolve(X::class)
     *   - App::make(X::class)             (facade)
     *
     * Returns the resolved abstract (class-string or alias) + the AST node to report
     * at, or null if the node is unrelated.
     *
     * @return array{class-string|string, Node}|null
     */
    private static function classifyResolution(Node $node, ?string $containerParamName): ?array
    {
        // $app->make(...), $this->app->make(...), app()->make(...), etc.
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && isset(self::RESOLVER_METHODS[\strtolower($node->name->name)])
            && self::looksLikeContainer($node->var, $containerParamName)
        ) {
            $abstract = self::extractAbstract($node->getArgs());

            if ($abstract !== null) {
                return [$abstract, $node];
            }

            return null;
        }

        // $app[X::class], $this->app['request']
        if ($node instanceof ArrayDimFetch
            && self::looksLikeContainer($node->var, $containerParamName)
        ) {
            $abstract = self::literalAbstractFrom($node->dim);

            if ($abstract !== null) {
                return [$abstract, $node];
            }

            return null;
        }

        // app(X::class), resolve(X::class)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && \in_array($node->name->toLowerString(), ['app', 'resolve'], true)
        ) {
            $abstract = self::extractAbstract($node->getArgs());

            if ($abstract !== null) {
                return [$abstract, $node];
            }

            return null;
        }

        // App::make(X::class) — the facade. The facade resolves from the globally-bound
        // container, which in a typical application is the same instance we are binding
        // into (the one managed by Illuminate\Container\Container::setInstance()).
        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && isset(self::RESOLVER_METHODS[\strtolower($node->name->name)])
            && $node->class instanceof Name
            && self::isAppFacade($node->class)
        ) {
            $abstract = self::extractAbstract($node->getArgs());

            if ($abstract !== null) {
                return [$abstract, $node];
            }
        }

        return null;
    }

    /**
     * Does $expr look like the container inside the closure scope?
     * True for: the declared container param ($app), $this->app, and app()/resolve().
     */
    private static function looksLikeContainer(Node $expr, ?string $containerParamName): bool
    {
        if ($containerParamName !== null
            && $expr instanceof Variable
            && $expr->name === $containerParamName
        ) {
            return true;
        }

        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && $expr->name->name === 'app'
        ) {
            return true;
        }

        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && \in_array($expr->name->toLowerString(), ['app', 'resolve'], true)
            && $expr->getArgs() === [];
    }

    private static function isAppFacade(Name $class): bool
    {
        /** @psalm-var mixed $resolved */
        $resolved = $class->getAttribute('resolvedName');

        if (\is_string($resolved)) {
            return $resolved === \Illuminate\Support\Facades\App::class;
        }

        // Fallback for root-namespace alias (the generated `class App extends \Illuminate\Support\Facades\App {}`
        // alias stub means unqualified `App::make(...)` in application code resolves to the facade).
        return $class->toString() === 'App';
    }

    /**
     * From the args of make()/get()/app()/resolve(), extract the first positional
     * argument only — that is the "abstract" in Laravel's container API. Extra
     * arguments (parameters for makeWith) are not relevant to this rule.
     *
     * @param array<array-key, Arg> $args
     * @return class-string|string|null
     */
    private static function extractAbstract(array $args): ?string
    {
        foreach ($args as $arg) {
            return self::literalAbstractFrom($arg->value);
        }

        return null;
    }

    /**
     * Extract a known request-scoped abstract from a literal expression node
     * (class-const-fetch or a known-alias string literal). The string branch is
     * deliberately restricted to Laravel's alias keys; arbitrary strings do not
     * match.
     *
     * @return class-string|string|null
     */
    private static function literalAbstractFrom(?Node $node): ?string
    {
        if ($node instanceof ClassConstFetch
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && \strtolower($node->name->name) === 'class'
        ) {
            /** @psalm-var mixed $resolved */
            $resolved = $node->class->getAttribute('resolvedName');

            if (\is_string($resolved) && isset(self::REQUEST_SCOPED_CLASSES[$resolved])) {
                /** @psalm-var class-string */
                return $resolved;
            }

            return null;
        }

        if ($node instanceof String_ && isset(self::REQUEST_SCOPED_ALIASES[$node->value])) {
            return $node->value;
        }

        return null;
    }

    /**
     * @return \Generator<Node>
     *
     * @param iterable<Node> $nodes
     */
    private static function walkWithoutNestedClosures(iterable $nodes): \Generator
    {
        foreach ($nodes as $node) {
            yield from self::walkNode($node);
        }
    }

    /**
     * @return \Generator<Node>
     */
    private static function walkNode(Node $node): \Generator
    {
        yield $node;

        foreach ($node->getSubNodeNames() as $name) {
            /** @psalm-var mixed $sub */
            $sub = $node->$name;

            if ($sub instanceof Closure || $sub instanceof ArrowFunction) {
                // The nested closure itself is a Node, but we don't descend into its
                // body — see findResolutions() docblock.
                continue;
            }

            if ($sub instanceof Node) {
                yield from self::walkNode($sub);
                continue;
            }

            if (\is_array($sub)) {
                /** @psalm-var mixed $item */
                foreach ($sub as $item) {
                    if ($item instanceof Closure || $item instanceof ArrowFunction) {
                        continue;
                    }

                    if ($item instanceof Node) {
                        yield from self::walkNode($item);
                    }
                }
            }
        }
    }

    private static function emitIssue(
        AfterExpressionAnalysisEvent $event,
        string $methodName,
        string $abstract,
        Node $node,
    ): void {
        IssueBuffer::accepts(
            new OctaneIncompatibleBinding(
                \sprintf(
                    "Request-scoped '%s' resolved inside %s() closure — state leaks across Octane requests. Use bind() or resolve at call site.",
                    $abstract,
                    $methodName,
                ),
                new CodeLocation($event->getStatementsSource(), $node),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }
}
