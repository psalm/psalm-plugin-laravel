<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth\Concerns;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TLiteralString;

trait ExtractsGuardNameFromCallLike
{
    public static function getGuardNameFromFirstArgument(CallLike $stmt, string $default_guard, StatementsSource $source): ?string
    {
        $call_args = $stmt->getArgs();
        if ($call_args === []) {
            return $default_guard;
        }

        $first_arg_type_expr = $call_args[0]->value;

        if ($first_arg_type_expr instanceof String_) {
            return $first_arg_type_expr->value;
        }

        // A literal null argument is equivalent to no argument — both resolve the default guard.
        // e.g. guard(null) behaves identically to guard() at runtime.
        if ($first_arg_type_expr instanceof ConstFetch && $first_arg_type_expr->name->toLowerString() === 'null') {
            return $default_guard;
        }

        return self::getGuardNameFromEnumCase($first_arg_type_expr, $source); // null when guard unknown
    }

    /**
     * Resolves `guard(Guards::Admin)` for a string-backed enum case to its backing value
     * ('admin'), the same guard name a literal `guard('admin')` resolves to.
     *
     * Reads the argument's AST directly (a `ClassConstFetch`) rather than its inferred
     * Psalm type: for the facade form (`Auth::guard(...)`) `guard` only exists as a parent
     * `@method` pseudo-declaration, and — unlike a real method call — Psalm has not yet
     * populated the node type provider for the argument by the time this return-type
     * provider runs, so `$source->getNodeTypeProvider()->getType(...)` comes back null
     * there. The AST shape is available regardless of that ordering.
     *
     * Deliberately declines (returns null) for:
     *  - int-backed enums — Laravel guard names are always strings.
     *  - pure (non-backed) enums — `enum_value()` falls back to `->name` for these at
     *    runtime, so they ARE technically resolvable, but the case name is not guaranteed
     *    to match a config guard key the way a backing value is, and this narrowing has
     *    not been scoped to cover that path yet (see issue #1389).
     *  - dynamic case expressions (e.g. `$class::$case`), `self`/`static`/`parent` (guard
     *    enums aren't declared inline in a way that makes those meaningful here), or
     *    classes Psalm cannot resolve to enum storage.
     */
    private static function getGuardNameFromEnumCase(Expr $expr, StatementsSource $source): ?string
    {
        if (
            !$expr instanceof ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || $expr->class->isSpecialClassName()
        ) {
            return null;
        }

        /** @var string|null $fqcn */
        $fqcn = $expr->class->getAttribute('resolvedName');
        if (!\is_string($fqcn)) {
            $fqcn = $expr->class->toString();
        }

        try {
            $storage = $source->getCodebase()->classlike_storage_provider->get(\strtolower($fqcn));
        } catch (\InvalidArgumentException) {
            return null; // unresolvable enum class
        }

        if ($storage->enum_type !== 'string') {
            return null; // int-backed or pure enum — out of scope, see docblock above
        }

        $case = $storage->enum_cases[$expr->name->name] ?? null;
        if ($case === null) {
            return null; // not an enum case (e.g. a plain class constant) or unknown case
        }

        try {
            $value = $case->getValue($source->getCodebase()->classlikes);
        } catch (\UnexpectedValueException) {
            return null; // unresolvable case value (deferred constant expression)
        }

        return $value instanceof TLiteralString ? $value->value : null;
    }
}
