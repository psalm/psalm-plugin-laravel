<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows `App::make()`, `App::makeWith()` and `App::get()` on the `Illuminate\Support\Facades\App`
 * facade to the resolved class, mirroring the container's own conditional return type
 * `($abstract is class-string<TClass> ? TClass : mixed)`.
 *
 * Why a handler and not a stub: Laravel declares these as `object|mixed` (collapses to `mixed`). A
 * real stub method is shadowed by Laravel's reflected magic-method tag on static calls, and a
 * class-docblock `@method` override can't express templates/conditional returns in Psalm 7.
 *
 * Why not {@see \Psalm\LaravelPlugin\Util\ContainerResolver}: `make` dispatches via
 * `Facade::__callStatic`, and in `AtomicStaticCallAnalyzer` the return-type provider for that branch
 * fires *before* arguments are analysed (a Psalm hook-ordering limitation), so `NodeTypeProvider::getType()`
 * is null for every argument.
 * We read the class off the AST node instead. (That resolver also probes the container, which throws
 * for unbound user classes like a Nova action — the motivating case.)
 *
 * Why the params provider: once we return a non-null type, `AtomicStaticCallAnalyzer` calls
 * `checkMethodArgs()` → `Methods::getMethodParams()`, which throws `UnexpectedValueException`
 * ("Cannot get method params") for a magic method with no real params. {@see self::getMethodParams()}
 * supplies them so analysis doesn't abort.
 *
 * Limitation: a `class-string<Foo>` *variable* first argument (vs a `Foo::class` literal) isn't
 * narrowed — its type is unavailable at this analysis stage — so it keeps Laravel's `mixed`.
 *
 * @internal
 */
final class AppFacadeMakeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    private const FACADE_FQCN = 'Illuminate\\Support\\Facades\\App';

    /**
     * Container-resolution methods carrying the `class-string<T> -> T` contract; all narrow alike.
     *
     * @var array<lowercase-string, true>
     */
    private const HANDLED_METHODS = [
        'make' => true,
        'makewith' => true,
        'get' => true,
    ];

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // Psalm keys providers on the exact called class, so cover the canonical facade plus
        // its aliases (the global `\App`, project aliases) via FacadeMapProvider.
        return [
            self::FACADE_FQCN,
            ...FacadeMapProvider::getFacadeClasses(ApplicationProvider::getAppFullyQualifiedClassName()),
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (!isset(self::HANDLED_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        return self::resolveFirstArgumentClass($event);
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method = $event->getMethodNameLowercase();

        if (!isset(self::HANDLED_METHODS[$method])) {
            return null;
        }

        // Mirror the real signatures so argument checking (incl. named args) stays faithful:
        // get(string $id) is single-arg; make()/makeWith() use `abstract` + optional `parameters`.
        if ($method === 'get') {
            return [new FunctionLikeParameter('id', false, Type::getString(), is_optional: false)];
        }

        return [
            new FunctionLikeParameter('abstract', false, Type::getString(), is_optional: false),
            new FunctionLikeParameter('parameters', false, Type::getArray(), is_optional: true),
        ];
    }

    private static function resolveFirstArgumentClass(MethodReturnTypeProviderEvent $event): ?Union
    {
        $paramName = $event->getMethodNameLowercase() === 'get' ? 'id' : 'abstract';
        $abstract = self::abstractArgument($event->getCallArgs(), $paramName);

        // Only a `X::class` literal carries the class on the AST; a plain-string alias or a
        // `class-string<Foo>` variable can't be narrowed here (see class docblock) — defer to mixed.
        if (
            !$abstract instanceof ClassConstFetch
            || !$abstract->class instanceof Name
            || !$abstract->name instanceof Identifier
            || $abstract->name->toLowerString() !== 'class'
        ) {
            return null;
        }

        if ($abstract->class->isSpecialClassName()) {
            return self::resolveRelativeClassName($abstract->class->toLowerString(), $event->getSource());
        }

        $fqcn = $abstract->class->getAttribute('resolvedName');

        if (!\is_string($fqcn) || $fqcn === '') {
            return null;
        }

        return new Union([new TNamedObject($fqcn)]);
    }

    /**
     * The abstract/id expression, matched by name first so reordered named arguments
     * (`make(parameters: [...], abstract: Foo::class)`) still narrow, then by position.
     *
     * @param list<Arg> $args
     */
    private static function abstractArgument(array $args, string $paramName): ?Expr
    {
        foreach ($args as $arg) {
            if ($arg->name instanceof Identifier && $arg->name->toLowerString() === $paramName) {
                return $arg->value;
            }
        }

        foreach ($args as $arg) {
            if ($arg->name === null) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * Resolve `self::class` / `static::class` against the calling class (`static` keeps late static
     * binding: `Foo&static`). `parent::class` is rare here and left to Laravel's `@method`.
     */
    private static function resolveRelativeClassName(string $keyword, StatementsSource $source): ?Union
    {
        if ($keyword !== 'self' && $keyword !== 'static') {
            return null;
        }

        $callingClass = $source->getFQCLN();

        if ($callingClass === null) {
            return null;
        }

        // Inside a trait, `self`/`static` resolve to the consuming class at runtime, but getFQCLN()
        // yields the trait here — so the inferred type would be the (non-instantiable) trait. Defer
        // to mixed rather than emit a wrong type.
        if (self::isTrait($source->getCodebase(), $callingClass)) {
            return null;
        }

        return new Union([new TNamedObject($callingClass, is_static: $keyword === 'static')]);
    }

    /** @psalm-mutation-free */
    private static function isTrait(Codebase $codebase, string $fqcn): bool
    {
        try {
            return $codebase->classlike_storage_provider->get($fqcn)->is_trait;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
