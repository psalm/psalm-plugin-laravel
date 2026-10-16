<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Enumerable;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * Rewrites the bare collections inside the parameter types of a function-like storage.
 *
 * @see BareCollectionParamHandler
 *
 * @internal
 */
final class BareCollectionParamRewriter extends MutableTypeVisitor
{
    /**
     * Per class: what a bare use of it stands for, or null when Psalm leaves it bare.
     *
     * @var array<lowercase-string, non-empty-list<Union>|null>
     */
    private array $omittedArguments = [];

    /**
     * Classes whose bounds are being expanded on the current path.
     *
     * @var array<lowercase-string, true>
     */
    private array $expanding = [];

    /** Count of cycles found while expanding bounds; a parameter that hit one is left alone. */
    private int $cycles = 0;

    /** @psalm-mutation-free */
    public function __construct(private readonly Codebase $codebase) {}

    public function rewrite(FunctionLikeStorage $storage): void
    {
        foreach ($storage->params as $param) {
            if (!$param->type instanceof Union) {
                continue;
            }

            $type = $param->type;
            $cyclesBefore = $this->cycles;
            $this->traverse($type);

            if ($this->cycles !== $cyclesBefore) {
                continue;
            }

            // Psalm tells a native-only parameter from a docblock one by the identity of the two types,
            // and only infers a closure literal's parameter from the expected callable for the former
            // (`->chunk(10, function (Collection $c) {...})`), so keep them one object.
            if ($param->signature_type === $param->type) {
                $param->signature_type = $type;
            }

            $param->type = $type;
        }
    }

    #[\Override]
    protected function enterNode(TypeNode &$type): ?int
    {
        if ($type instanceof TNamedObject && $type::class === TNamedObject::class) {
            $generic = $this->expand($type);

            if ($generic instanceof \Psalm\Type\Atomic\TGenericObject) {
                $type = $generic;
            }

            return self::DONT_TRAVERSE_CHILDREN;
        }

        // Callable and closure parameters are left alone: rewriting them changes no verdict.
        // Everything else below is a place TypeExpander recurses into for an argument type.
        return $type instanceof Union
            || $type instanceof TArray
            || $type instanceof TGenericObject
            || $type instanceof TIterable
            || $type instanceof TKeyedArray
            || $type instanceof TObjectWithProperties
            ? null
            : self::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * What Psalm expands a bare use of the class to, with the arguments marked omitted; null when Psalm
     * leaves the class bare (not a collection, no templates, or only `mixed` bounds) or it cannot be expanded.
     */
    private function expand(TNamedObject $type): ?TGenericObject
    {
        if ($type->extra_types !== [] || !$this->codebase->classOrInterfaceExists($type->value)) {
            return null;
        }

        // classOrInterfaceExists() resolves class_alias() names, the storage provider does not.
        $name = $this->codebase->classlikes->getUnAliasedName($type->value);

        // A class Psalm knows to exist may still have no storage (e.g. a PHP 8.4 builtin enum such as
        // PropertyHookType): not proven to be a collection, so leave the type bare.
        try {
            $storage = $this->codebase->classlike_storage_provider->get($name);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        $key = \strtolower($storage->name);

        if (!\array_key_exists($key, $this->omittedArguments)) {
            $this->omittedArguments[$key] = $this->omittedArgumentsOf($storage);
        }

        $arguments = $this->omittedArguments[$key];

        if ($arguments === null) {
            return null;
        }

        // The bounds may name collections themselves (`@template C of Collection`), and those bare uses
        // are expanded as well, so one pass is final: a second pass over the result changes nothing.
        // Cycles (`@template T of SelfRef` on SelfRef) would never end. Leaving only the inner use bare
        // would not be stable (a second pass would expand it), so the whole parameter is left alone.
        if (isset($this->expanding[$key])) {
            ++$this->cycles;

            return null;
        }

        $this->expanding[$key] = true;

        // Bounds belong to the class storage: the visitor is copy-on-write, so they are never mutated.
        foreach ($arguments as &$argument) {
            $this->traverse($argument);
        }

        unset($argument, $this->expanding[$key]);

        return new TGenericObject($type->value, $arguments);
    }

    /**
     * The arguments Psalm expands a bare use of the class to, each marked omitted; null when Psalm leaves
     * the class bare (not a collection, no templates, or only `mixed` bounds).
     *
     * @return non-empty-list<Union>|null
     * @psalm-capabilities read-props
     */
    private function omittedArgumentsOf(ClassLikeStorage $storage): ?array
    {
        if ($storage->template_types === null
            || $storage->template_types === []
            || !\array_any($storage->template_types, static fn(array $bounds): bool => !self::bound($bounds)->hasMixed())
            || !$this->isEnumerable($storage->name)
        ) {
            return null;
        }

        return \array_values(\array_map(
            static fn(array $bounds): Union => self::bound($bounds)->setProperties(['had_template' => true]),
            $storage->template_types,
        ));
    }

    /**
     * @param non-empty-array<string, Union> $bounds a template's bound, keyed by its defining class
     * @psalm-pure
     */
    private static function bound(array $bounds): Union
    {
        return \reset($bounds);
    }

    /**
     * @psalm-capabilities read-props
     */
    private function isEnumerable(string $class): bool
    {
        return \strcasecmp($class, Enumerable::class) === 0
            || $this->codebase->classImplements($class, Enumerable::class)
            || $this->codebase->interfaceExtends($class, Enumerable::class);
    }
}
