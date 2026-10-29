<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Carbon\CarbonInterface;
use Carbon\Factory;
use Carbon\FactoryImmutable;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;

/**
 * The date class `Date::use()` left in the booted app: one reader for everything that types what
 * `Date::` / `Date::use()` produces, namely the model `date` / `datetime` casts, the `withPivot()` timestamp
 * columns and the `Date` facade (#1999). `now()` / `today()` are not routed here: they sample the exact call.
 *
 * Read from DateFactory's statics, which are protected with no accessor. The read is cheap and the statics can
 * change between reads in tests, so nothing is cached.
 *
 * @internal
 */
final class ConfiguredDateClass
{
    /**
     * The object type every `Date::` factory call returns in the booted app; null when the configured class is
     * known NOT to be a Carbon (its own factories decide), which callers turn into `mixed`.
     *
     * - Unconfigured: `Illuminate\Support\Carbon`, with no storage lookup (the framework default is always declared).
     * - `Date::use(X::class)`, X a `CarbonInterface` in Psalm's storage: X. A Carbon is the only class provably
     *   handing back what the factory returns: CarbonInterface's `static` returns bind every override, while another
     *   class's `createFromFormat()` and friends may return anything.
     * - X a `CarbonInterface` at runtime (the app already loaded it) that Psalm never scanned: `CarbonInterface`,
     *   as a TNamedObject without storage breaks analysis.
     * - A stock `Carbon\Factory` / `FactoryImmutable` (the exact class: a subclass may override `__call()`)
     *   builds every date as `$className::$method()`, so its `getClassName()` gets the same rules.
     * - A callable, a factory subclass or any other factory: `CarbonInterface`, a trust rather than a proof.
     *   DateFactory's `@method` tags declare Carbon, and `date` / `immutable_*` casts call Carbon-only methods;
     *   a `datetime`-only app may still return a plain `DateTimeInterface`. `mixed` left such apps untyped.
     * - A `Date::swap()`ed or rebound `date` service (the facade root, read first) is judged like a factory: its
     *   class is read when it is a stock Carbon factory, any other object (a `DateFactory` subclass included) is `CarbonInterface`;
     *   only the stock `DateFactory` root defers to the statics.
     * - Any `CarbonInterface` answer needs that interface in Psalm's storage, else null.
     */
    public static function type(Codebase $codebase): ?TNamedObject
    {
        $root = self::facadeRoot();
        if ($root !== null && $root::class !== DateFactory::class) {
            // `Date::swap()` or a rebound `date` service: the statics below are no longer what builds the dates.
            return self::fromFactory($codebase, $root);
        }

        // The statics are protected with no accessor; `dateClass` is only ever set to a class name by `DateFactory::use()`.
        $read = static fn(string $name): mixed => (new \ReflectionProperty(DateFactory::class, $name))->getValue();
        /** @var string|null $class */
        $class = $read('dateClass');
        $callable = $read('callable');
        /** @var object|null $factory */
        $factory = $read('factory');

        if ($callable !== null) {
            return self::carbonInterface($codebase);
        }

        if ($factory !== null) {
            return self::fromFactory($codebase, $factory);
        }

        if ($class === null) {
            return new TNamedObject(\Illuminate\Support\Carbon::class);
        }

        return self::fromClass($codebase, $class);
    }

    /** The object the `Date` facade resolves to, null when it resolves to none (no app, or the lookup throws). */
    private static function facadeRoot(): ?object
    {
        try {
            /** @var object|null $root */
            $root = Date::getFacadeRoot();
        } catch (\Throwable) {
            return null;
        }

        return $root;
    }

    private static function fromFactory(Codebase $codebase, object $factory): ?TNamedObject
    {
        // A stock Carbon factory builds every date as `$className::$method()` (`Factory::__call()`); a subclass or
        // another object may build anything.
        if (!$factory instanceof Factory || !\in_array($factory::class, [Factory::class, FactoryImmutable::class], true)) {
            return self::carbonInterface($codebase);
        }

        return self::fromClass($codebase, $factory->getClassName());
    }

    private static function fromClass(Codebase $codebase, string $class): ?TNamedObject
    {
        // One spelling per class (no leading backslash, `class_alias()` resolved): a second autoload attempt
        // after a failed load would die with "Cannot redeclare".
        $class = ClassLineage::canonicalName($codebase, $class);

        $storage = ClassLineage::storage($codebase, $class);
        if ($storage instanceof ClassLikeStorage) {
            return ClassLineage::isA($codebase, $class, CarbonInterface::class) ? new TNamedObject($storage->name) : null;
        }

        // Psalm never scanned it: ask the runtime, loading the class at most once (#1652). A class that fails to
        // load is unknown; the declared-class guard keeps `is_a()` from autoloading it again.
        AutoloadOnce::attempt($class);

        return \class_exists($class, false) && \is_a($class, CarbonInterface::class, true)
            ? self::carbonInterface($codebase)
            : null;
    }

    /** @psalm-mutation-free */
    private static function carbonInterface(Codebase $codebase): ?TNamedObject
    {
        return ClassLineage::storage($codebase, CarbonInterface::class) instanceof ClassLikeStorage
            ? new TNamedObject(CarbonInterface::class)
            : null;
    }
}
