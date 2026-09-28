<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;

/**
 * Resolves a `Foo::BAR` argument (enum case or class constant) to the string Laravel sees at runtime.
 *
 * Reads the AST (`ClassConstFetch`) plus class storage rather than the argument's inferred type: on a
 * facade `@method` pseudo-method call, Psalm has not yet populated the node type provider for the
 * arguments when a return-type provider runs, so `getNodeTypeProvider()->getType()` is null there.
 *
 * Every unresolvable shape answers null ("not proven"), never a guess.
 *
 * @internal
 */
final class ClassConstStringResolver
{
    /**
     * String-backed enum case only: `Guards::Admin` → its backing value.
     *
     * Declines pure enums, int-backed enums, plain class constants, and `self`/`static`/`parent`.
     */
    public static function backedEnumCase(Expr $expr, StatementsSource $source): ?string
    {
        $fetch = self::fetchParts($expr);

        if ($fetch === null) {
            return null;
        }

        [$class, $name] = $fetch;

        if ($class->isSpecialClassName()) {
            return null;
        }

        $storage = self::storage(self::resolvedName($class), $source);

        return $storage instanceof \Psalm\Storage\ClassLikeStorage ? self::enumCaseValue($storage, $name, $source, false) : null;
    }

    /**
     * What Laravel's `enum_value()` yields for an enum case (backing value, or the case name of a pure
     * enum), or the value of a class constant with a single string literal type. `self::` resolves to
     * the enclosing class; `static::` and `parent::` decline (late static binding / not worth it).
     */
    public static function enumValueOrConstant(Expr $expr, StatementsSource $source): ?string
    {
        $fetch = self::fetchParts($expr);

        if ($fetch === null) {
            return null;
        }

        [$class, $name] = $fetch;

        if ($class->toLowerString() === 'self') {
            $fqcn = $source->getFQCLN();
        } elseif ($class->isSpecialClassName()) {
            return null;
        } else {
            $fqcn = self::resolvedName($class);
        }

        $storage = self::storage($fqcn, $source);

        // `self::` inside a trait binds to the using class at runtime, which the trait's storage cannot see.
        if (!$storage instanceof ClassLikeStorage || $storage->is_trait) {
            return null;
        }

        if (isset($storage->enum_cases[$name])) {
            return self::enumCaseValue($storage, $name, $source, true);
        }

        try {
            $type = $source->getCodebase()->classlikes->getClassConstantType(
                $storage->name,
                $name,
                \ReflectionProperty::IS_PRIVATE,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }

        return $type instanceof Union && $type->isSingleStringLiteral() ? $type->getSingleStringLiteral()->value : null;
    }

    /**
     * @return array{Name, string}|null
     * @psalm-mutation-free
     */
    private static function fetchParts(Expr $expr): ?array
    {
        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return null;
        }

        return [$expr->class, $expr->name->name];
    }

    private static function resolvedName(Name $class): string
    {
        /** @psalm-var string|null $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $class->toString();
    }

    private static function storage(?string $fqcn, StatementsSource $source): ?ClassLikeStorage
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }

        try {
            return $source->getCodebase()->classlike_storage_provider->get(\strtolower($fqcn));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function enumCaseValue(ClassLikeStorage $storage, string $name, StatementsSource $source, bool $allowPure): ?string
    {
        $case = $storage->enum_cases[$name] ?? null;

        if ($case === null) {
            return null;
        }

        if ($storage->enum_type === null) {
            return $allowPure ? $name : null;
        }

        try {
            $value = $case->getValue($source->getCodebase()->classlikes);
        } catch (\UnexpectedValueException) {
            return null; // deferred constant expression Psalm could not resolve
        }

        // TLiteralInt for an int-backed case: declined, config disk/guard keys are strings.
        return $value instanceof TLiteralString ? $value->value : null;
    }
}
