<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TLiteralString;

/**
 * The string a name argument carries at runtime, when it is statically known.
 *
 * Reads the AST plus class storage, not the inferred type: on a facade `@method` call Psalm runs
 * return-type providers before analysing the arguments, so the node type is not available yet.
 *
 * @internal
 */
final class StaticStringResolver
{
    /**
     * A string literal, a string-backed enum case (Laravel unwraps it to `->value`), or a class
     * constant typed as one string literal. Everything else, including int-backed and pure enum
     * cases, `static::` / `parent::`, and `self::` inside a trait, answers null.
     */
    public static function resolve(?Expr $expr, StatementsSource $source): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return null;
        }

        /** @psalm-var string|null $resolved */
        $resolved = $expr->class->getAttribute('resolvedName');
        $fqcn = $expr->class->toLowerString() === 'self'
            ? $source->getFQCLN()
            : ($expr->class->isSpecialClassName() ? null : $resolved ?? $expr->class->toString());

        if ($fqcn === null) {
            return null;
        }

        $const = $expr->name->name;
        $codebase = $source->getCodebase();

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($fqcn));
            // `self::` in a trait binds to the using class, which the trait's storage cannot see.
            if ($storage->is_trait) {
                return null;
            }

            if (isset($storage->enum_cases[$const])) {
                $value = $storage->enum_cases[$const]->getValue($codebase->classlikes);

                return $value instanceof TLiteralString ? $value->value : null;
            }

            $type = $codebase->classlikes->getClassConstantType($storage->name, $const, \ReflectionProperty::IS_PRIVATE);
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }

        return $type?->isSingleStringLiteral() === true ? $type->getSingleStringLiteral()->value : null;
    }
}
