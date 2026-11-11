<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Proof obligations shared by the Psalter fixes that rewrite a magic-forwarded Eloquent call
 * (`Post::make()`, `Post::where()`) into an explicit one (`new Post()`, `Post::query()->where()`).
 *
 * The rewrite is only behavior-preserving when the call really takes the stock route, so each helper answers
 * "can this be disproved?" and a fix is declined (the issue still reported) when it can't be proven.
 *
 * @internal
 */
final class ModelCallGuard
{
    /**
     * Classes whose own declarations count as stock. `declaring_method_ids` names the declaring trait, not the using
     * class, for a method a framework class gets from a trait, so `forwardCallTo()` (from `ForwardsCalls`) is
     * declared by the trait for every stock model. Keys are lowercase.
     */
    private const STOCK_DECLARING_CLASSES = [
        'illuminate\\database\\eloquent\\model' => true,
        'illuminate\\support\\traits\\forwardscalls' => true,
    ];

    /**
     * The `boot<Trait>()` methods the framework's own `Model` traits declare: not a customization. Any other
     * method starting with `boot` that is not declared by `Model` (`bootSoftDeletes()`, a user trait's) is.
     */
    private const STOCK_BOOT_METHOD_IDS = [
        'illuminate\\database\\eloquent\\concerns\\hasevents::boothasevents' => true,
        'illuminate\\database\\eloquent\\concerns\\hasglobalscopes::boothasglobalscopes' => true,
    ];

    private const SCOPED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\ScopedBy';

    /**
     * Attributes Laravel reads while booting and initializing a model. Compared by name (lowercase): `Boot` and
     * `Initialize` do not exist in every supported Laravel version.
     */
    private const BOOT_ATTRIBUTES = [
        'illuminate\\database\\eloquent\\attributes\\scopedby' => true,
        'illuminate\\database\\eloquent\\attributes\\boot' => true,
        'illuminate\\database\\eloquent\\attributes\\initialize' => true,
    ];

    /**
     * Whether the model's class chain (ancestors and traits included) declares any of $methods outside the framework
     * (see {@see self::STOCK_DECLARING_CLASSES}). A method that comes from a trait is declared by that trait, so a
     * trait override is caught the same way. An unreadable class counts as overriding: a fix needs proof, a report
     * does not.
     *
     * @param class-string<Model> $modelClass
     * @param list<lowercase-string> $methods
     *
     * @psalm-capabilities read-props
     */
    public static function overridesAny(Codebase $codebase, string $modelClass, array $methods): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return true;
        }

        foreach ($methods as $method) {
            $declaring = $classStorage->declaring_method_ids[$method] ?? null;

            if ($declaring !== null && !isset(self::STOCK_DECLARING_CLASSES[\strtolower($declaring->fq_class_name)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `Model::method()` written here can run as `$this->__call()`. PHP keeps the object context of a
     * named static call when `$this` is an instance of the named class, so the instance's class, table and
     * connection apply, while `new Model()` / `Model::query()` always build a fresh model. That is possible in a
     * non-static method or closure whose `$this` is the model, one of its subclasses, or one of its ancestors (the
     * base class's `$this` can be the model at runtime). A trait or an unreadable `$this` cannot be ruled out. A
     * static method or a static closure has no object context, even though Psalm still lists `$this` in a static
     * method's scope.
     *
     * @param class-string<Model> $modelClass
     */
    public static function mayRunInObjectContextOf(Codebase $codebase, string $modelClass, StatementsSource $source, Context $context): bool
    {
        $thisType = $context->vars_in_scope['$this'] ?? null;

        if ($thisType === null || $source->isStatic()) {
            return false;
        }

        foreach ($thisType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject) {
                return true;
            }

            $thisClass = $atomicType->value;

            if (\strcasecmp($thisClass, $modelClass) === 0) {
                return true;
            }

            try {
                $thisStorage = $codebase->classlike_storage_provider->get(\strtolower($thisClass));

                if (
                    $thisStorage->is_trait
                    || $thisStorage->is_interface
                    || $codebase->classExtends($thisClass, $modelClass)
                    || $codebase->classExtends($modelClass, $thisClass)
                ) {
                    return true;
                }
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an edit at this call site may not hold for every run of the code. One source edit is shared by
     * everything that executes that source, so it is withheld where the context is not a fixed one:
     *  - inside a trait, whose body is analyzed once per consuming class but edited once, so a consumer that
     *    proves it safe would rewrite the source for the consumers that don't;
     *  - when the call's dispatch depends on `$this` (`$dependsOnThis`: a named static call such as `Post::where()`),
     *    inside a non-static closure or arrow function, which `Closure::bind()` / `bindTo()` / `call()` can rebind
     *    to any object (even one declared in a static method), turning the call into `$this->__call()` of that
     *    object. Only the innermost closure matters: a `static` one cannot be rebound. An instance call on a
     *    captured model (`$post->where()`) does not read `$this`, so rebinding does not change it.
     */
    public static function isUnsafeEditSite(StatementsSource $source, bool $dependsOnThis): bool
    {
        $closureSeen = false;

        for ($current = $source, $depth = 0; $depth < 64; ++$depth) {
            if ($current instanceof TraitAnalyzer) {
                return true;
            }

            if ($dependsOnThis && !$closureSeen && $current instanceof ClosureAnalyzer) {
                if (!$current->isStatic()) {
                    return true;
                }

                $closureSeen = true;
            }

            $parent = $current->getSource();

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }

        // An unexpectedly deep source chain is not proven to be a safe site.
        return true;
    }

    /**
     * Whether booting or initializing the model can change what `make()` returns: a `boot()` / `booting()` /
     * `booted()` override or a trait's `boot<Trait>()` (a method starting with `boot` declared outside the framework
     * `Model`), a method marked `#[Boot]` or `#[Initialize]` whatever its name, or a `#[ScopedBy]` attribute. A global
     * scope registered there can add pending attributes through its `extend()`, which `make()` applies and `new` does
     * not. This also covers `SoftDeletes`. The attributes are looked for where Laravel discovers them: on the model,
     * its ancestors and the traits they use (`HasGlobalScopes::resolveGlobalScopeAttributes()` reads the class and its
     * traits; `Model::bootTraits()` reflects every method the class has, trait methods included). An unreadable
     * class counts as customized.
     *
     * @param class-string<Model> $modelClass
     */
    public static function customizesBoot(Codebase $codebase, string $modelClass): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));

            foreach ($classStorage->declaring_method_ids as $method => $declaring) {
                if (
                    \str_starts_with($method, 'boot')
                    && !isset(self::STOCK_DECLARING_CLASSES[\strtolower($declaring->fq_class_name)])
                    && !isset(self::STOCK_BOOT_METHOD_IDS[\strtolower((string) $declaring)])
                ) {
                    return true;
                }
            }

            // The class and every trait below it, at any depth: Laravel reflects each class's own traits only, but a
            // trait's methods and attributes reach the model through every trait that uses it. `$seen` also ends cycles.
            $seen = [];

            foreach ([\strtolower($modelClass), ...\array_keys($classStorage->parent_classes)] as $class) {
                // The framework's own `Model` and the traits it uses are stock.
                if ($class === 'illuminate\\database\\eloquent\\model') {
                    continue;
                }

                for ($pending = [$class]; $pending !== [];) {
                    $name = \array_pop($pending);

                    if (isset($seen[$name])) {
                        continue;
                    }

                    $seen[$name] = true;
                    $carrier = $codebase->classlike_storage_provider->get($name);

                    if (self::carriesBootAttribute($codebase, $carrier)) {
                        return true;
                    }

                    foreach (\array_keys($carrier->used_traits) as $trait) {
                        $pending[] = $trait;
                    }
                }
            }
        } catch (\InvalidArgumentException) {
            return true;
        }

        return false;
    }

    /**
     * Whether the class (or trait) carries `#[ScopedBy]` or a subclass of it (Laravel reads it with
     * `ReflectionAttribute::IS_INSTANCEOF`), or one of its own methods carries `#[Boot]` or `#[Initialize]`. `Boot`
     * and `Initialize` are newer than the supported Laravel floor, so the attributes are compared by name and never
     * referenced as classes. An attribute class whose parents cannot be read counts as `ScopedBy`.
     */
    private static function carriesBootAttribute(Codebase $codebase, ClassLikeStorage $carrier): bool
    {
        foreach ($carrier->attributes as $attribute) {
            $name = $attribute->fq_class_name;

            if (isset(self::BOOT_ATTRIBUTES[\strtolower($name)])) {
                return true;
            }

            if ($codebase->classExists($name)) {
                try {
                    if ($codebase->classExtends($name, self::SCOPED_BY)) {
                        return true;
                    }
                } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                    return true;
                }
            }
        }

        foreach ($carrier->methods as $method) {
            foreach ($method->attributes as $attribute) {
                if (isset(self::BOOT_ATTRIBUTES[\strtolower($attribute->fq_class_name)])) {
                    return true;
                }
            }
        }

        return false;
    }
}
