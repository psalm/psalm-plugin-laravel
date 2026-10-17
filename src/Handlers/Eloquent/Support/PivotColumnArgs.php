<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Statically provable column names of `withPivot()` / `withTimestamps()` call arguments. Every
 * "not provable" shape returns null, so callers decline rather than guess.
 *
 * @internal
 */
final class PivotColumnArgs
{
    /**
     * Columns a `withPivot(...)` call selects, mirroring `is_array($columns) ? $columns : func_get_args()`:
     * a first-argument array literal is the whole list (later arguments are ignored at runtime),
     * otherwise every argument is a column name.
     *
     * Null = not statically provable: no args, any unpack, any named arg (`withPivot(columns: ...)` binds the
     * variadic differently), a non-literal or keyed item, an empty-string name, or an empty array.
     *
     * @param list<Arg|VariadicPlaceholder|\PhpParser\Node\ArgPlaceholder> $args
     * @return ?non-empty-list<non-empty-string>
     * @psalm-capabilities read-props
     */
    public static function withPivot(array $args): ?array
    {
        $plain = [];

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof \PhpParser\Node\Identifier) {
                return null;
            }

            $plain[] = $arg;
        }

        if ($plain === []) {
            return null;
        }

        $first = $plain[0]->value;
        $columns = [];

        if ($first instanceof Array_) {
            foreach ($first->items as $item) {
                if (
                    !$item instanceof ArrayItem
                    || $item->unpack
                    || $item->byRef
                    || $item->key instanceof Expr
                    || !$item->value instanceof String_
                    || $item->value->value === ''
                ) {
                    return null;
                }

                $columns[] = $item->value->value;
            }

            return $columns === [] ? null : $columns;
        }

        foreach ($plain as $arg) {
            if (!$arg->value instanceof String_ || $arg->value->value === '') {
                return null;
            }

            $columns[] = $arg->value->value;
        }

        return $columns;
    }

    /**
     * Columns a `withTimestamps($createdAt = null, $updatedAt = null)` call selects (vendor
     * `BelongsToMany::withTimestamps()` / `createdAt()` / `updatedAt()`): named args `createdAt` / `updatedAt`
     * are accepted; null or omitted selects the parent's column name, a string literal selects that name,
     * `false` selects nothing for that slot.
     *
     * Null = not provable: unpack, unknown or repeated named arg, extra args, a non-literal arg, a defaulted
     * slot while $declaringModel is null (unknown or union parent: never guess its names), or a defaulted slot
     * whose parent column name is null (Laravel then falls back to `Model::CREATED_AT`, which is not modelled).
     *
     * @param list<Arg|VariadicPlaceholder|\PhpParser\Node\ArgPlaceholder> $args
     * @param ?class-string<Model> $declaringModel
     * @return ?list<non-empty-string>
     */
    public static function withTimestamps(array $args, ?string $declaringModel): ?array
    {
        /** @var array{createdAt: ?Arg, updatedAt: ?Arg} $slots */
        $slots = ['createdAt' => null, 'updatedAt' => null];
        $position = 0;

        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString() ?? match ($position++) {
                0 => 'createdAt',
                1 => 'updatedAt',
                default => null,
            };

            // Extra positional args, unknown or repeated named args: not provable.
            if ($name === null || !\array_key_exists($name, $slots) || $slots[$name] instanceof Arg) {
                return null;
            }

            $slots[$name] = $arg;
        }

        $defaults = null;
        $columns = [];

        foreach (['createdAt' => 0, 'updatedAt' => 1] as $slot => $defaultIndex) {
            $value = $slots[$slot]?->value;

            if ($value instanceof ConstFetch && \strtolower($value->name->toString()) === 'false') {
                continue;
            }

            if ($value === null || ($value instanceof ConstFetch && \strtolower($value->name->toString()) === 'null')) {
                // Unknown parent (null, or not instantiable): never guess the default names.
                $defaults ??= PivotColumnTypes::parentTimestampNames($declaringModel);
                if ($defaults === null) {
                    return null;
                }

                $default = $defaults[$defaultIndex];
                if ($default === null) {
                    return null;
                }

                $columns[] = $default;

                continue;
            }

            if (!$value instanceof String_ || $value->value === '') {
                return null;
            }

            $columns[] = $value->value;
        }

        return $columns;
    }
}
