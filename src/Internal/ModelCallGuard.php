<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Proof obligations for the Psalter fix that rewrites a magic-forwarded Eloquent call (`Post::where()`) into an
 * explicit one (`Post::query()->where()`), plus the model-class helpers the model call rules share.
 *
 * The rewrite is only behavior-preserving when the call really takes the stock route, so each proof helper
 * answers "can this be disproved?" and a fix is declined (the issue still reported) when it can't be proven.
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

        return self::storageOverridesAny($classStorage, $methods);
    }

    /**
     * Whether any scanned class that descends from $modelClass overrides one of $methods (same rule as
     * {@see self::overridesAny()}, applied to each descendant), or declares $calledMethod itself (a declaration other
     * than the one $modelClass already has, so an inherited `#[Scope]` method does not count). The runtime object
     * behind a `$model->where()` or a `$this->where()` can be any subclass, and its `__call()` or its own `where()` is
     * the code that runs, while the rewrite would skip it. Descendants Psalm never scanned stay invisible
     * (documented known gap). Unprovable counts as overriding.
     *
     * @param class-string<Model> $modelClass
     * @param list<lowercase-string> $methods
     * @param lowercase-string $calledMethod
     */
    public static function descendantOverridesAny(Codebase $codebase, string $modelClass, array $methods, string $calledMethod): bool
    {
        $descendants = ClassDescendants::of($codebase, $modelClass);

        if ($descendants === null) {
            return true;
        }

        try {
            $ownDeclaration = $codebase->classlike_storage_provider->get(\strtolower($modelClass))->declaring_method_ids[$calledMethod] ?? null;
        } catch (\InvalidArgumentException) {
            return true;
        }

        foreach ($descendants as $descendant) {
            if (self::storageOverridesAny($descendant, $methods)) {
                return true;
            }

            $declaring = $descendant->declaring_method_ids[$calledMethod] ?? null;

            if ($declaring !== null && ($ownDeclaration === null || \strcasecmp($declaring->fq_class_name, $ownDeclaration->fq_class_name) !== 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<lowercase-string> $methods
     *
     * @psalm-capabilities read-props
     */
    private static function storageOverridesAny(ClassLikeStorage $classStorage, array $methods): bool
    {
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
     * connection apply, while `Model::query()` always builds a fresh model. That is possible in a
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
     * Whether the call's dispatch can be changed by rebinding the closure it sits in. One source edit is shared by
     * everything that executes that source, so it is withheld where the binding is not a fixed one.
     * `Closure::bind()` / `bindTo()` / `call()` can rebind the closure to any object (even one declared in a static
     * method), and even a `static` closure keeps no fixed class scope: `Closure::bind($closure, null, Other::class)`
     * resolves its `self` / `static` / `parent` to `Other`. So:
     *  - $readsThis (a named static call such as `Post::where()`, which turns into `$this->__call()` of the rebound
     *    object, or a receiver containing `$this`) makes a non-static closure or arrow function unsafe;
     *  - $readsClassScope (a receiver containing a `self` / `static` / `parent` name) makes any closure or arrow
     *    function unsafe.
     * Only the innermost closure matters. An instance call on a captured model (`$post->where()`) reads neither.
     *
     * Trait bodies are not decided here: {@see PsalterFix::fixOrReport()} declines every fix inside a trait.
     */
    public static function isUnsafeEditSite(StatementsSource $source, bool $readsThis, bool $readsClassScope): bool
    {
        if (!$readsThis && !$readsClassScope) {
            return false;
        }

        for ($current = $source, $depth = 0; $depth < 64; ++$depth) {
            if ($current instanceof ClosureAnalyzer) {
                return $readsClassScope || !$current->isStatic();
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
     * Whether $className is {@see Model} or a subclass of it. A class whose subclass link cannot be proven
     * (`classExtends()` throws on missing or aliased storage, and on storage that is not populated yet) is
     * not a model.
     *
     * @psalm-assert-if-true class-string<Model> $className
     */
    public static function isModelSubclass(string $className, Codebase $codebase): bool
    {
        if ($className === Model::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        try {
            return $codebase->classExtends($className, Model::class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }

    /** @psalm-pure */
    public static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
