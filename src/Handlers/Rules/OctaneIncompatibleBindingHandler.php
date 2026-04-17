<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\OctaneIncompatibleBinding;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

/**
 * Flags request-scoped service resolutions inside long-lived binding closures
 * (singleton, singletonIf).
 *
 * Under Laravel Octane, the application instance is reused across requests, so a
 * closure that resolves Request/Session/Auth during the first resolution keeps
 * that instance alive for every subsequent request.
 *
 * scoped()/scopedIf() bindings are NOT flagged: Octane calls
 * {@see \Illuminate\Container\Container::forgetScopedInstances()} between requests,
 * so scoped captures are re-created per request. They are the Octane-safe
 * alternative to singleton() and are out of scope for this rule.
 *
 * Handler is opt-in: registered only when findOctaneIncompatibleBinding is set
 * in psalm.xml. When registered, the hook fires for every resolved MethodCall
 * and StaticCall; we reject StaticCall immediately (facade-form bindings like
 * `App::singleton(...)` are out of scope) and match remaining calls in O(1) via
 * an isset() lookup on the declaring method id.
 *
 * @see https://laravel.com/docs/octane#dependency-injection-and-octane
 * @see https://github.com/larastan/larastan/blob/3.x/src/Rules/OctaneCompatibilityRule.php
 */
final class OctaneIncompatibleBindingHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * Method IDs (declaring-class::method, lowercased) for container bindings that
     * register a single shared instance reused across requests.
     *
     *  - bind()/bindIf() are safe: they re-execute the closure per resolution.
     *  - scoped()/scopedIf() are safe under Octane: flushed between requests via
     *    Container::forgetScopedInstances(). Not flagged.
     *
     * Covers both the concrete Container and the contract, because declaring_method_id
     * points at whichever one is in scope for the receiver type. Users who type
     * $this->app as the contract hit the contract rows; users typing the concrete
     * class hit the Container rows.
     *
     * Keys are hard-coded lowercase strings. ::class is allowed in const arrays on
     * PHP 8.3+, but it preserves the source-code casing verbatim: a mis-cased
     * `\Illuminate\container\Container::class` at the declaration site would silently
     * produce a key that never matches strtolower(getDeclaringMethodId()). Literal
     * lowercase strings eliminate that trap.
     */
    private const UNSAFE_METHOD_IDS = [
        'illuminate\\container\\container::singleton' => true,
        'illuminate\\container\\container::singletonif' => true,
        'illuminate\\contracts\\container\\container::singleton' => true,
        'illuminate\\contracts\\container\\container::singletonif' => true,
    ];

    /**
     * Core request-scoped Laravel services. Resolving any of these inside a shared
     * binding closure captures state from the first resolving request and leaks it
     * into every subsequent request under Octane.
     *
     * Sources:
     *  - {@see \Illuminate\Foundation\Application::registerCoreContainerAliases()}
     *    for the alias -> class mapping (request, session, cookie, auth, config, url).
     *  - {@see \Illuminate\Auth\AuthServiceProvider::register()} for Authenticatable,
     *    which is bound at register time, not via the alias table.
     *
     * Illuminate\Routing\Route is intentionally NOT listed: it is a plain data object,
     * not a container-bound service. The "current route" is accessed via the Router
     * or facade, not by resolving Route::class.
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
        \Illuminate\Config\Repository::class => true,
        \Illuminate\Contracts\Config\Repository::class => true,
        \Illuminate\Routing\UrlGenerator::class => true,
        \Illuminate\Contracts\Routing\UrlGenerator::class => true,
        \Illuminate\Routing\Redirector::class => true,
    ];

    /**
     * String aliases for request-scoped services (keys Laravel registers in
     * registerCoreContainerAliases()). Resolving via alias string is equivalent to
     * resolving via class-string.
     */
    private const REQUEST_SCOPED_ALIASES = [
        'request' => true,
        'auth' => true,
        'auth.driver' => true,
        'session' => true,
        'session.store' => true,
        'cookie' => true,
        'config' => true,
        'url' => true,
        'redirect' => true,
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
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        // The event also fires for StaticCall. Facade-form bindings like
        // `App::singleton(...)` are intentionally out of scope for this rule
        // (they're much rarer than the `$this->app->singleton(...)` pattern).
        if (!$expr instanceof MethodCall) {
            return;
        }

        if (!isset(self::UNSAFE_METHOD_IDS[\strtolower($event->getDeclaringMethodId())])) {
            return;
        }

        $args = $expr->getArgs();

        if (\count($args) < 2) {
            return;
        }

        $closure = $args[1]->value;

        if (!$closure instanceof Closure && !$closure instanceof ArrowFunction) {
            return;
        }

        $containerParamName = self::getFirstParamName($closure);
        $methodName = $expr->name instanceof Identifier ? $expr->name->name : 'singleton';

        foreach (self::findResolutions($closure, $containerParamName) as [$abstract, $node]) {
            self::emitIssue($event, $methodName, $abstract, $node);
        }
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
     * We deliberately do not descend into nested closures / arrow functions. They
     * define their own execution scope: resolutions performed inside them happen
     * at invocation time of the inner closure, not during the outer shared-binding
     * closure's one-and-only execution, so attributing them to the outer binding
     * would be a false positive.
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
     * Returns the resolved abstract (class-string or alias) plus the AST node to
     * report at, or null if the node is unrelated.
     *
     * Known gap: `Container::getInstance()->make(...)` is not detected. Rare enough
     * that the added complexity would not pay off.
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

        // App::make(X::class). The facade resolves from the globally-bound container,
        // which in a typical application is the same instance we are binding into.
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

        // Fallback for root-namespace alias. The generated
        // `class App extends \Illuminate\Support\Facades\App {}` alias stub means
        // unqualified `App::make(...)` in application code resolves to the facade.
        return $class->toString() === 'App';
    }

    /**
     * From the args of make() / get() / app() / resolve(), extract the first
     * positional argument only, which is the "abstract" in Laravel's container API.
     * Extra arguments (parameters for makeWith) are not relevant to this rule.
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

            if (self::isScopeBoundary($sub)) {
                // Do not descend into nested execution scopes. See findResolutions() docblock.
                continue;
            }

            if ($sub instanceof Node) {
                yield from self::walkNode($sub);
                continue;
            }

            if (\is_array($sub)) {
                /** @psalm-var mixed $item */
                foreach ($sub as $item) {
                    if (self::isScopeBoundary($item)) {
                        continue;
                    }

                    if ($item instanceof Node) {
                        yield from self::walkNode($item);
                    }
                }
            }
        }
    }

    /**
     * Nodes whose bodies form a separate execution scope: their statements do not
     * run during the outer shared-binding closure's resolution.
     *
     *   - FunctionLike covers Closure, ArrowFunction, Function_, ClassMethod.
     *   - Class_, Trait_, Interface_, Enum_ cover anonymous / nested class-like
     *     declarations inside the closure; method bodies inside them are lazy.
     *
     * @psalm-pure
     * @psalm-assert-if-true FunctionLike|Class_|Trait_|Interface_|Enum_ $node
     */
    private static function isScopeBoundary(mixed $node): bool
    {
        return $node instanceof FunctionLike
            || $node instanceof Class_
            || $node instanceof Trait_
            || $node instanceof Interface_
            || $node instanceof Enum_;
    }

    private static function emitIssue(
        AfterMethodCallAnalysisEvent $event,
        string $methodName,
        string $abstract,
        Node $node,
    ): void {
        IssueBuffer::accepts(
            new OctaneIncompatibleBinding(
                \sprintf(
                    "Request-scoped '%s' resolved inside %s() closure. State leaks across Octane requests. Use bind() or resolve at call site.",
                    $abstract,
                    $methodName,
                ),
                new CodeLocation($event->getStatementsSource(), $node),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }
}
