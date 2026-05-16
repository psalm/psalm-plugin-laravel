<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider;

/**
 * Pure AST → accessor-string → class-string harvester driven by
 * {@see \Psalm\LaravelPlugin\Providers\BootTimeProviderHarvester}.
 *
 * Kept separate so it can be unit-tested with just PhpParser + NameResolver,
 * no Composer / file-system / `Codebase` plumbing required. The driver class
 * is responsible for "which providers are in scope, how do we get their AST";
 * this class is responsible for "given the body of a provider method, which
 * `accessor → service-class` pairs can we statically prove".
 *
 * Binding shapes recognised by {@see inspectBindingCall()}:
 *
 *   $this->app->bind|singleton|instance|scoped|bindIf|singletonIf|scopedIf($accessor, ServiceClass::class)
 *   $this->app->bind|singleton('accessor', fn () => new ServiceClass(...))
 *   $this->app->bind|singleton('accessor', function () { return new ServiceClass(...); })
 *   $this->app->alias(ServiceClass::class, 'accessor')          // reverse argument order
 *   app()->bind(...) / \Illuminate\Support\Facades\App::bind(...)
 *
 * Shapes intentionally skipped (would need runtime evaluation or branch analysis):
 *
 *   $this->app->bind('accessor', config('foo.driver'))          // dynamic concrete
 *   $this->app->bind('accessor', function () {
 *       return match (...) { 'a' => new A, 'b' => new B };      // branching closure
 *   })
 *   $this->app->bind($abstract, ...);                           // dynamic accessor
 *   $this->app->register(SubProvider::class);                   // see BootTimeProviderHarvester docblock
 *
 * @internal
 */
final class ProviderBindingHarvester
{
    /** Methods on the container that bind an accessor → class in their first two args. */
    private const FORWARD_BINDING_METHODS = [
        'bind' => true,
        'singleton' => true,
        'instance' => true,
        'scoped' => true,
        'bindif' => true,
        'singletonif' => true,
        'scopedif' => true,
    ];

    /** `alias($abstract, $alias)` swaps the argument order vs. forward bindings. */
    private const ALIAS_METHOD = 'alias';

    /**
     * Walk the statements of a single method body (usually `register()`) and
     * record every binding it exposes into {@see ContainerBindingMapProvider}.
     * Walks recursively so bindings nested in `if (config(...))` / `try` / loop
     * blocks are still captured — the consumer side can't see those conditions
     * either, so the static-best-effort answer is to record the binding either way.
     *
     * Prefer {@see harvestClassMethods()} when a full provider class is available;
     * it walks every method, which catches the common Laravel pattern of `register()`
     * delegating to private helpers like `bindFacades()` / `bindConcretes()` (issue #942
     * cites `imdhemy/laravel-in-app-purchases` doing exactly that).
     *
     * @param array<array-key, Stmt> $stmts
     */
    public static function harvest(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            self::visitNode($stmt);
        }
    }

    /**
     * Walk every method body declared on a `ServiceProvider` subclass and harvest
     * bindings from each. Catches the delegated-binding pattern where `register()`
     * is a thin dispatcher (`$this->bindFacades(); $this->bindConcretes();`) and
     * the actual `bind(...)` calls live in sibling methods. Walking `boot()` too
     * is intentional: some packages bind in `boot()` rather than `register()`, and
     * over-coverage is harmless (the harvester records only well-formed binding
     * shapes, abstract methods have no body, etc.).
     */
    public static function harvestClassMethods(Class_ $class): void
    {
        foreach ($class->getMethods() as $method) {
            if ($method->stmts !== null) {
                self::harvest($method->stmts);
            }
        }
    }

    /**
     * Cheap, hand-written recursive visitor. NodeFinder + NodeTraverser would do
     * this generically but allocate iterators per node; this code runs on every
     * scanned `ServiceProvider` subclass on every cold scan, so the loop body is
     * kept tight on purpose.
     */
    private static function visitNode(Node $node): void
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            self::inspectBindingCall($node);
        }

        foreach ($node->getSubNodeNames() as $name) {
            /** @psalm-var mixed $child */
            $child = $node->{$name};

            if ($child instanceof Node) {
                self::visitNode($child);
                continue;
            }

            if (!\is_array($child)) {
                continue;
            }

            /** @var mixed $element */
            foreach ($child as $element) {
                if ($element instanceof Node) {
                    self::visitNode($element);
                }
            }
        }
    }

    /**
     * Inspect a call site that might be one of the container's binding methods.
     *
     * Receiver shapes accepted:
     *   - `$this->app->bind(...)` (MethodCall on a PropertyFetch on `$this`)
     *   - `app()->bind(...)`      (MethodCall on a `FuncCall` whose name is `app`)
     *   - `App::bind(...)`        (StaticCall on `Illuminate\Support\Facades\App`)
     */
    private static function inspectBindingCall(MethodCall|StaticCall $call): void
    {
        $methodName = self::methodNameLower($call);
        if ($methodName === null) {
            return;
        }

        $isAlias = $methodName === self::ALIAS_METHOD;

        if (!$isAlias && !isset(self::FORWARD_BINDING_METHODS[$methodName])) {
            return;
        }

        if (!self::receivesContainer($call)) {
            return;
        }

        $args = $call->getArgs();
        if (\count($args) < 2) {
            return;
        }

        $first = $args[0];
        $second = $args[1];

        if ($isAlias) {
            // alias($abstract, $alias): first arg is the class, second is the accessor.
            $serviceClass = self::extractClassFromArg($first);
            $accessor = self::extractLiteralString($second->value);
        } else {
            // bind|singleton|instance|scoped|bindif|singletonif($accessor, $concrete):
            // first is accessor, second is the concrete (class-string or factory closure).
            $accessor = self::extractLiteralString($first->value);
            $serviceClass = self::extractClassFromArg($second);
        }

        if ($accessor === null || $serviceClass === null) {
            return;
        }

        ContainerBindingMapProvider::record($accessor, $serviceClass);
    }

    /** @psalm-mutation-free */
    private static function methodNameLower(MethodCall|StaticCall $call): ?string
    {
        return $call->name instanceof Identifier
            ? \strtolower($call->name->toString())
            : null;
    }

    /**
     * True when the call receiver looks like the container instance.
     *
     * `$this->app->...` is the canonical form inside a `ServiceProvider`. Helpers
     * (`app()->...`) and the `App` facade are also accepted because they resolve to the
     * same container at runtime. The check is deliberately lenient: harvest is already
     * gated to `ServiceProvider::register()` bodies, so the small risk of matching an
     * unrelated `$this->app->bind(...)` (a non-container `app` property happens to live
     * on `$this`) is minimal.
     */
    private static function receivesContainer(MethodCall|StaticCall $call): bool
    {
        if ($call instanceof StaticCall) {
            return $call->class instanceof Node\Name
                && \in_array(
                    \strtolower((string) ($call->class->getAttribute('resolvedName') ?? $call->class->toString())),
                    ['illuminate\\support\\facades\\app', 'app'],
                    true,
                );
        }

        $receiver = $call->var;

        // $this->app->bind(...)
        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && \is_string($receiver->var->name)
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'app'
        ) {
            return true;
        }

        // app()->bind(...)
        if ($receiver instanceof FuncCall
            && $receiver->name instanceof Node\Name
            && \strcasecmp((string) ($receiver->name->getAttribute('resolvedName') ?? $receiver->name->toString()), 'app') === 0
        ) {
            return true;
        }

        return false;
    }

    /**
     * Extract the concrete service class from a binding's second argument.
     *
     * Two shapes succeed:
     *   - `ServiceClass::class` — a direct {@see ClassConstFetch} whose target is a Name.
     *   - Closure / arrow fn whose body returns `new ServiceClass(...)`.
     *
     * Anything else (string literal class names, variables, complex factories) returns
     * null. String FQCN literals are intentionally excluded — Laravel accepts them, but
     * they're rare in modern code and supporting them would force us to validate that
     * the literal references an existing class, which adds a `class_exists` check on a
     * hot path for marginal coverage gain.
     *
     * @return ?class-string
     */
    private static function extractClassFromArg(Arg $arg): ?string
    {
        $expr = $arg->value;

        if ($class = self::classFromConstFetch($expr)) {
            return $class;
        }

        if ($expr instanceof ArrowFunction) {
            return self::classFromNewExpr($expr->expr);
        }

        if ($expr instanceof Closure) {
            $lastReturn = self::findLastReturnExpr($expr->stmts);
            return $lastReturn !== null ? self::classFromNewExpr($lastReturn) : null;
        }

        return null;
    }

    /**
     * Resolve a `Foo::class` expression to its FQCN. Relies on Psalm's NameResolver
     * pass having attached `resolvedName` during scan — the attribute is set for every
     * `Name` node by the time `afterClassLikeVisit` fires.
     *
     * @return ?class-string
     */
    private static function classFromConstFetch(Expr $expr): ?string
    {
        if (!$expr instanceof ClassConstFetch
            || !$expr->name instanceof Identifier
            || \strtolower($expr->name->toString()) !== 'class'
            || !$expr->class instanceof Node\Name
        ) {
            return null;
        }

        return self::resolveNameToClassString($expr->class);
    }

    /**
     * `new ServiceClass(...)` → `ServiceClass` FQCN. Anonymous classes (`new class { }`)
     * and dynamic targets (`new $variable(...)`) are skipped — neither yields a stable
     * class-string we can pair with an accessor.
     *
     * @return ?class-string
     */
    private static function classFromNewExpr(?Expr $expr): ?string
    {
        if (!$expr instanceof New_ || !$expr->class instanceof Node\Name) {
            return null;
        }

        return self::resolveNameToClassString($expr->class);
    }

    /**
     * Pull the FQCN off a `Name` node. Prefers the NameResolver-attached `resolvedName`
     * (which already accounts for `use` aliasing and namespace prefixes) and falls back
     * to the raw text when the attribute is absent — happens for global-namespace names
     * resolved without a NameResolver run, which is unusual but defensively handled.
     *
     * @return ?class-string
     */
    private static function resolveNameToClassString(Node\Name $name): ?string
    {
        /** @psalm-var mixed $resolved */
        $resolved = $name->getAttribute('resolvedName');

        if (\is_string($resolved) && $resolved !== '') {
            /** @var class-string */
            return \ltrim($resolved, '\\');
        }

        /** @var class-string */
        return \ltrim($name->toString(), '\\');
    }

    /**
     * Find the expression returned by the last `return` statement in a closure body.
     * Walks bottom-up so a final `return` after side-effecting setup wins over earlier
     * returns inside conditionals — matches the common "guard plus return" pattern
     * without trying to interpret branching.
     *
     * Returns null when the closure has no return or its last return is `return;` or
     * `return $variable;` — anything other than a direct `new X(...)` we can't pin to
     * a class without dataflow analysis we don't run here.
     *
     * @param array<array-key, Stmt> $stmts
     * @psalm-mutation-free
     */
    private static function findLastReturnExpr(array $stmts): ?Expr
    {
        $values = \array_values($stmts);

        for ($i = \count($values) - 1; $i >= 0; $i--) {
            $stmt = $values[$i];

            if ($stmt instanceof Return_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * @return ?non-empty-string
     * @psalm-mutation-free
     */
    private static function extractLiteralString(Expr $expr): ?string
    {
        if (!$expr instanceof Node\Scalar\String_) {
            return null;
        }

        return $expr->value === '' ? null : $expr->value;
    }
}
