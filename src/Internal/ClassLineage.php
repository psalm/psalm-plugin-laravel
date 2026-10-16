<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TArrayKey;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Class ancestry from Psalm's class storage, never PHP's autoloader.
 *
 * `\is_a($class, X::class, true)` on a class name taken from an analyzed type loads that class's file.
 * Psalm's error handler stays active after the plugin boots (and in forked workers), so a load-time
 * deprecation or warning in the analyzed project becomes a thrown exception that ends the whole run
 * (#1253, #1652).
 *
 * @internal
 * @psalm-immutable
 */
final class ClassLineage
{
    /**
     * $class is $ancestor, extends it, or implements it (an interface: extends it).
     *
     * Reads the populated storage maps directly rather than `Codebase::classExtendsOrImplements()`:
     * - it never throws (that API throws InvalidArgumentException on a class Psalm never scanned, so every
     *   caller needs a catch);
     * - it records no code_use_graph reference, unlike `Codebase::classExists()`;
     * - it answers `I2 extends I1` and `Model is Model`, where `classExtendsOrImplements()` returns false
     *   (`classExtends()` is non-reflexive and `classImplements()` ignores interface parents).
     *
     * Both names go through {@see canonicalName()}. Psalm keys the ancestry maps by the name a declaration
     * wrote, so `implements SomeAlias` is stored under the alias: when the direct lookup misses, each entry
     * is canonicalized too (the miss path only, so the common hit stays a single isset). A class Psalm never
     * scanned is "not proven" (false).
     *
     * @template T of object
     * @param class-string<T> $ancestor
     * @psalm-assert-if-true class-string<T> $class
     * @psalm-mutation-free
     */
    public static function isA(Codebase $codebase, string $class, string $ancestor): bool
    {
        $ancestorLc = \strtolower(self::canonicalName($codebase, $ancestor));
        $class = self::canonicalName($codebase, $class);
        if (\strtolower($class) === $ancestorLc) {
            return true;
        }

        $storageProvider = $codebase->classlike_storage_provider;
        if (!$storageProvider->has($class)) {
            return false;
        }

        $storage = $storageProvider->get($class);

        if (
            isset($storage->parent_classes[$ancestorLc])
            || isset($storage->class_implements[$ancestorLc])
            || isset($storage->parent_interfaces[$ancestorLc])
        ) {
            return true;
        }

        foreach ([$storage->parent_classes, $storage->class_implements, $storage->parent_interfaces] as $ancestry) {
            foreach ($ancestry as $name) {
                if (\strtolower(self::canonicalName($codebase, $name)) === $ancestorLc) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Storage for $class under its canonical name, or null when Psalm never scanned it.
     *
     * @psalm-mutation-free
     */
    public static function storage(Codebase $codebase, string $class): ?ClassLikeStorage
    {
        $class = self::canonicalName($codebase, $class);
        $storageProvider = $codebase->classlike_storage_provider;

        return $storageProvider->has($class) ? $storageProvider->get($class) : null;
    }

    /**
     * What a receiver atomic binds an ancestor's template to (`Builder::TModel`, `Enumerable::TKey`): the
     * receiver's own type param when the ancestor is the receiver itself, a type the subclass extends it
     * with (`@extends Collection<int, X>`), or the receiver's type param at the position of the template it
     * forwards, matched by the defining class (a name match alone would read another slot).
     *
     * `template_extended_params` states each ancestor's template in its DIRECT child's terms, so a forwarded
     * template (`TRelatedModel:HasOneOrMany`) is followed up the chain until the receiver's own template.
     * Null when nothing is provable: unknown or unpopulated class, no such ancestor, a non-generic receiver
     * whose own template is forwarded, or a chain that does not end.
     *
     * @param class-string $ancestor
     * @psalm-capabilities read-props
     */
    public static function projectedTemplate(Codebase $codebase, TNamedObject $atomic, string $ancestor, string $template): ?Union
    {
        $typeParams = $atomic instanceof TGenericObject ? $atomic->type_params : [];

        try {
            $storage = $codebase->classlike_storage_provider->get($atomic->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        if (\strcasecmp($storage->name, $ancestor) === 0) {
            $position = \array_search($template, \array_keys($storage->template_types ?? []), true);

            return $position === false ? null : ($typeParams[$position] ?? null);
        }

        $value = $storage->template_extended_params[$ancestor][$template] ?? null;
        for ($hops = 0; $hops < 8 && $value instanceof Union; ++$hops) {
            $forwarded = $value->isSingle() ? $value->getSingleAtomic() : null;
            if (!$forwarded instanceof TTemplateParam) {
                return self::isTemplateFree($value) ? $value : null;
            }

            if (\strcasecmp($forwarded->defining_class, $storage->name) === 0) {
                $position = \array_search($forwarded->param_name, \array_keys($storage->template_types ?? []), true);

                return $position === false ? null : ($typeParams[$position] ?? null);
            }

            $value = $storage->template_extended_params[$forwarded->defining_class][$forwarded->param_name] ?? null;
        }

        return null;
    }

    /**
     * True when every node of the type is a plain scalar, class or generic-class atomic: no template
     * reference of any kind survives (`K|int`, `key-of<T>`, `T[K]`, conditionals, `class-string<T>`).
     * A whitelist of exact classes, not a blacklist of template shapes, so a node kind nobody
     * anticipated declines instead of leaking an unresolved template into a caller's scope.
     *
     * @psalm-mutation-free
     */
    private static function isTemplateFree(Union $type): bool
    {
        $visitor = new class extends TypeVisitor {
            public bool $free = true;

            /** @psalm-external-mutation-free */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                if (!\in_array($type::class, [
                    Union::class,
                    TInt::class,
                    TLiteralInt::class,
                    TIntRange::class,
                    TString::class,
                    TLiteralString::class,
                    TNonEmptyString::class,
                    TNumericString::class,
                    TArrayKey::class,
                    TNamedObject::class,
                    TGenericObject::class,
                    TMixed::class,
                    TNull::class,
                    TBool::class,
                    TTrue::class,
                    TFalse::class,
                    TFloat::class,
                ], true)) {
                    $this->free = false;

                    return self::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        // The visitor mutates only its own flag, a local object: the call is observably pure.
        /** @psalm-suppress ImpureMethodCall */
        $visitor->traverse($type);

        return $visitor->free;
    }

    /**
     * The name storage is keyed by: no leading backslash (a valid spelling in cast strings and container
     * abstracts), and `class_alias()` aliases resolved. Psalm keeps an alias in method storage return
     * types, so an alias reaches handlers.
     *
     * @psalm-mutation-free
     */
    public static function canonicalName(Codebase $codebase, string $class): string
    {
        return $codebase->classlikes->getUnAliasedName(\ltrim($class, '\\'));
    }
}
