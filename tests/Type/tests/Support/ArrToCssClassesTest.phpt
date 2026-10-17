--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.50.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Arr::toCssClasses() (what Blade's `@class([...])` compiles to) skips falsy string-key
 * constraints at runtime, and `implode()` turns a `null` list item into an empty segment,
 * so a nullable item or a nullable string-key constraint is valid input, although Laravel's
 * docblock rejects it since 12.50.0.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1775
 */

// --- positive: a nullable list item (the `@class(['col-start-2', $maybe])` shape) ---

function test_nullable_list_item(?string $maybe): void
{
    $_result = Arr::toCssClasses(['col-start-2 bg-inherit', $maybe]);
    /** @psalm-check-type-exact $_result = string */
}

// --- positive: a nullable string-key constraint ---

function test_nullable_constraint(?string $maybe): void
{
    Arr::toCssClasses(['tab', 'is-active' => $maybe]);
}

// --- negative: the widening is `null` only, not `mixed` ---

function test_object_item_still_rejected(): void
{
    Arr::toCssClasses([new \stdClass()]);
}

function test_array_item_still_rejected(): void
{
    Arr::toCssClasses(['a' => ['nested']]);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Arr::toCssClasses expects %s, but list{stdClass} provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Arr::toCssClasses expects %s, but array{a: list{'nested'}} provided
