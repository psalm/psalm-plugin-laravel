--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * The motivating bug (#1070): a `class-string<Model>` holder calls `query()` then an
 * undefined scope. `$fqcn::query()` resolves to base `Builder<Model>`, whose @mixin + __call
 * silently swallowed the name; at runtime it forwards to Builder::__call and throws. Flag it.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1070
 *
 * @param class-string<Model> $fqcn
 */
function flags_undefined_scope_on_dynamic_model_string(string $fqcn): void
{
    $fqcn::query()->thisScopeDoesNotExist();
}

/** Real Eloquent\Builder method — must NOT be flagged. */
function allows_real_builder_method(string $fqcn): void
{
    /** @var class-string<Model> $fqcn */
    $fqcn::query()->where('id', 1);
}

/** Query\Builder method reached via @mixin — must NOT be flagged. */
function allows_query_builder_method(string $fqcn): void
{
    /** @var class-string<Model> $fqcn */
    $fqcn::query()->whereIn('id', [1, 2, 3]);
}

/** Dynamic where{Column} — runtime dynamicWhere never fatals, must NOT be flagged. */
function allows_dynamic_where(string $fqcn): void
{
    /** @var class-string<Model> $fqcn */
    $fqcn::query()->whereSomethingDynamic('x');
}

/** Registered Builder macro (testBuilderMacro, see macro-fixtures.php) — must NOT be flagged. */
function allows_builder_macro(string $fqcn): void
{
    /** @var class-string<Model> $fqcn */
    $fqcn::query()->testBuilderMacro();
}

/**
 * A bare `Builder<Model>` variable receiver (idiomatic in filters/pipelines and
 * `whereHas(..., fn (Builder $q) => ...)` closures) is type-identical to the dynamic-model
 * case but usually backs a concrete model at runtime — must NOT be flagged (provenance gate).
 *
 * @param Builder<Model> $builder
 */
function allows_undefined_on_bare_builder_param(Builder $builder): void
{
    $builder->anotherMissingScope();
}

/**
 * Variable-assigned builder is the documented limitation: the provenance gate only fires on a
 * direct `$class::query()->method()` receiver, so this is NOT flagged.
 *
 * @param class-string<Model> $fqcn
 */
function allows_undefined_on_assigned_builder(string $fqcn): void
{
    $query = $fqcn::query();
    $query->thisScopeDoesNotExist();
}

/**
 * Concrete-model builder is out of scope (the type gate requires exactly base Model), so a
 * `class-string<Customer>` holder is NOT flagged even for a clearly-undefined name.
 *
 * @param class-string<Customer> $fqcn
 */
function allows_undefined_on_concrete_model_builder(string $fqcn): void
{
    $fqcn::query()->thisScopeDoesNotExistOnCustomer();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Builder::thisscopedoesnotexist does not exist
