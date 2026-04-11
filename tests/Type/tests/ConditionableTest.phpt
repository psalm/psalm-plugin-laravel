--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;

// --- Conditionable::when() ---

/**
 * when() returns $this, preserving the concrete type for fluent chaining.
 * Without the stub, Psalm resolves the callable template to `mixed`.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/704
 */
function stringable_when_with_callback(Stringable $str): void
{
    $_result = $str->when(true, fn(Stringable $s) => $s->upper());
    /** @psalm-check-type-exact $_result = Illuminate\Support\Stringable&static */
}

/**
 * when() with only a condition (no callback) must still return $this, not mixed.
 * Laravel internally returns HigherOrderWhenProxy here, but the stub simplifies to $this.
 */
function stringable_when_without_callback(Stringable $str): void
{
    $_result = $str->when(true);
    /** @psalm-check-type-exact $_result = Illuminate\Support\Stringable&static */
}

// --- Conditionable::unless() ---

/**
 * unless() returns $this, preserving the concrete type for fluent chaining.
 */
function stringable_unless_with_callback(Stringable $str): void
{
    $_result = $str->unless(false, fn(Stringable $s) => $s->lower());
    /** @psalm-check-type-exact $_result = Illuminate\Support\Stringable&static */
}

// --- Tappable::tap() ---

/**
 * tap($callback) returns $this — the instance is unchanged after the tap.
 */
function stringable_tap_with_callback(Stringable $str): void
{
    $_result = $str->tap(static function (Stringable $s): void {
        $s->value();
    });
    /** @psalm-check-type-exact $_result = Illuminate\Support\Stringable&static */
}

/**
 * tap() without a callback returns $this.
 * Laravel's actual return type is HigherOrderTapProxy here, but the stub
 * simplifies to $this because conditional return types with $this do not
 * work in Psalm 7 trait stubs.
 */
function stringable_tap_without_callback(Stringable $str): void
{
    $_result = $str->tap();
    /** @psalm-check-type-exact $_result = Illuminate\Support\Stringable&static */
}

// --- Eloquent Builder fluent chains ---

class TestConditionableModel extends Model {}

/**
 * Regression test: ->when() on an Eloquent Builder must not return `mixed`,
 * otherwise the chained ->where() call would raise "Method does not exist on mixed".
 *
 * @param Builder<TestConditionableModel> $query
 * @param string|null $search
 * @return Builder<TestConditionableModel>
 */
function builder_when_preserves_builder_type(Builder $query, ?string $search): Builder
{
    return $query->when(
        $search,
        fn(Builder $q, string $v) => $q->where('name', 'like', "%$v%")
    )->where('active', true);
}

/**
 * unless() must also preserve the Builder type for fluent chaining.
 *
 * @param Builder<TestConditionableModel> $query
 * @return Builder<TestConditionableModel>
 */
function builder_unless_preserves_builder_type(Builder $query): Builder
{
    return $query->unless(
        false,
        fn(Builder $q) => $q->where('deleted_at', null)
    )->where('active', true);
}
?>
--EXPECT--
