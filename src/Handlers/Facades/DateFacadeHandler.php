<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Carbon;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves the runtime-configured date class for `Date` facade static calls
 * (`Date::now()`, `Date::parse()`, `Date::create*()`, `Date::instance()`, ...).
 *
 * The facade ships hardcoded `@method static \Illuminate\Support\Carbon ...` tags,
 * so without this handler every call infers `Illuminate\Support\Carbon` even when a
 * project swapped the date class via `Date::use(CarbonImmutable::class)` /
 * `Date::useClass(...)` in a service provider.
 *
 * Mechanism (same as {@see \Psalm\LaravelPlugin\Handlers\Helpers\NowTodayHandler}
 * for the `now()` / `today()` helpers): the plugin has a booted Laravel app, so we
 * call the helper at analysis time and read `get_class()` of the result — capturing
 * whatever the project configured rather than the hardcoded default.
 *
 * Rather than enumerate a method list, we read each method's declared `@method`
 * return type from the facade's pseudo-method storage and swap the `Carbon` atomic
 * for the configured class, preserving every other atomic. This keeps nullability
 * correct for free across Laravel versions (`createFromFormat()` is `Carbon|null` on
 * L12+, `Carbon|false` on L11) and covers all `create*` variants without a hardcoded list.
 * Methods whose return type does not mention `Carbon` (e.g. `useClass()`, `getLocale()`,
 * `withTimeZone()` returning `static`) are left to Psalm's own `@method` resolution.
 *
 * `maxValue()` / `minValue()` carry no `@method` tag on the Laravel 12/13 facade, so
 * there is no declared type to rewrite and they are out of scope (Larastan resolves them
 * via the underlying factory). On Laravel 11 they DO carry a Carbon `@method` tag, and the
 * storage-read approach picks them up there automatically — no special-casing needed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1154
 * @internal
 */
final class DateFacadeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * Resolved configured date class, cached for the analysis run.
     *
     * @var ?class-string
     */
    private static ?string $configuredClass = null;

    /** @var array<string, ?Union> retyped return type per method, cached for the run */
    private static array $returnCache = [];

    /**
     * @inheritDoc
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // Psalm dispatches return-type providers by exact FQCN, so the root-namespace
        // alias (`\Date`) must be registered alongside the facade FQCN — otherwise
        // `\Date::now()` falls back to the inherited `@method` tag. FacadeMapProvider
        // maps the `date` service (a DateFactory instance) to both.
        return [
            Date::class,
            ...FacadeMapProvider::getFacadeClasses(DateFactory::class),
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        return self::retypedReturn($event->getSource()->getCodebase(), $event->getMethodNameLowercase());
    }

    /**
     * Synthesise params for the methods we retype. Psalm runs `checkMethodArgs()` —
     * which calls `getMethodParams()` — only after a return-type provider yields a
     * non-null type for a pseudo `@method` call; without a params provider that path
     * fatals with "Cannot get method params" (same crash class as #454/#854). We mirror
     * exactly the methods {@see self::getMethodReturnType()} handles and reuse the
     * facade's own declared `@method` params.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();
        $methodNameLower = $event->getMethodNameLowercase();

        if (!self::retypedReturn($codebase, $methodNameLower) instanceof Union) {
            return null;
        }

        return self::pseudoMethod($codebase, $methodNameLower)?->params;
    }

    private static function retypedReturn(Codebase $codebase, string $methodNameLower): ?Union
    {
        if (!\array_key_exists($methodNameLower, self::$returnCache)) {
            $declared = self::pseudoMethod($codebase, $methodNameLower)?->return_type;

            self::$returnCache[$methodNameLower] = $declared instanceof Union
                ? self::swapCarbon($declared, self::configuredClass())
                : null;
        }

        return self::$returnCache[$methodNameLower];
    }

    /**
     * Replace every `Illuminate\Support\Carbon` atomic in $declared with $configured,
     * preserving all other atomics (`null`, `false`, ...). Returns null when $declared
     * contains no `Carbon` atomic — the caller treats that as "not a date-returning
     * method, defer to Psalm".
     *
     * @param class-string $configured
     * @psalm-pure
     */
    public static function swapCarbon(Union $declared, string $configured): ?Union
    {
        $carbonLower = \strtolower(Carbon::class);
        $hasCarbon = false;
        $atomics = [];

        foreach ($declared->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \strtolower($atomic->value) === $carbonLower) {
                $hasCarbon = true;
                $atomics[] = new TNamedObject($configured);
            } else {
                $atomics[] = $atomic;
            }
        }

        return $hasCarbon ? new Union($atomics) : null;
    }

    /** @return class-string */
    private static function configuredClass(): string
    {
        if (self::$configuredClass === null) {
            // now() delegates to Date::now(); both honour Date::use()/useClass() swaps,
            // so get_class() yields the project's configured date class (default: Carbon).
            $instance = \now();
            self::$configuredClass = \get_class($instance);
        }

        return self::$configuredClass;
    }

    /** @psalm-mutation-free */
    private static function pseudoMethod(Codebase $codebase, string $methodNameLower): ?MethodStorage
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(Date::class);
        } catch (\InvalidArgumentException) {
            // Facade storage missing — Psalm didn't scan the Date facade. Nothing to read;
            // its `@method` tags remain the authoritative fallback for typing.
            return null;
        }

        return $storage->pseudo_static_methods[$methodNameLower] ?? null;
    }
}
