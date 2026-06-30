--FILE--
<?php declare(strict_types=1);

/**
 * literal() helper return-type inference (handler: LiteralHandler).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1153
 *
 * Laravel: a single positional arg is passed through; otherwise (object) $args
 * yields a stdClass carrying those properties. The handler returns
 * stdClass&object{...} (stdClass primary) so the value is both assignable to
 * stdClass parameters AND keeps its per-property shape.
 *
 * Verifying the shape needs property reads, not just the full-intersection string:
 * an exact-type check on stdClass&object{...} only compares the primary stdClass
 * atomic and is blind to the object-shape member's property types (it still proves
 * the result is an intersection-with-shape rather than a bare stdClass / passthrough).
 * $r->a reads narrow exactly, so those are what pin the inferred property types.
 */

function literal_named_args(): void
{
    $_r = literal(a: 1, b: 'x');
    /** @psalm-check-type-exact $_r = stdClass&object{a:1, b:'x'} */

    // Pin the shape members exactly (the assertion above cannot — see file docblock).
    $_a = $_r->a;
    /** @psalm-check-type-exact $_a = 1 */
    $_b = $_r->b;
    /** @psalm-check-type-exact $_b = 'x' */
}

function literal_single_named_arg_is_object_not_passthrough(): void
{
    // array_is_list(['a' => 1]) === false, so this is the (object) cast path.
    $_r = literal(a: 1);
    /** @psalm-check-type-exact $_r = stdClass&object{a:1} */
    $_a = $_r->a;
    /** @psalm-check-type-exact $_a = 1 */
}

function literal_single_positional_arg_passthrough(string $s): void
{
    $_r = literal($s);
    /** @psalm-check-type-exact $_r = string */
}

function literal_multiple_positional_args(): void
{
    // Positional args contribute their index as the property name.
    $_r = literal(1, 2);
    /** @psalm-check-type-exact $_r = stdClass&object{0:1, 1:2} */
}

function literal_mixed_positional_and_named_args(): void
{
    // (object) [0 => 1, 'b' => 2] — positional contributes its index, named its name.
    $_r = literal(1, b: 2);
    /** @psalm-check-type-exact $_r = stdClass&object{0:1, b:2} */
    $_b = $_r->b;
    /** @psalm-check-type-exact $_b = 2 */
}

function literal_no_args_is_empty_stdclass(): void
{
    $_r = literal();
    /** @psalm-check-type-exact $_r = stdClass */
}

function consume_stdclass(stdClass $x): stdClass
{
    return $x;
}

/**
 * Regression: stdClass&object{...} must satisfy a stdClass parameter (and return
 * type) with no ArgumentTypeCoercion. Psalm's own (object) $array cast fails this
 * because it yields a bare object{...} that is not a subtype of stdClass.
 */
function literal_result_is_assignable_to_stdclass(): stdClass
{
    return consume_stdclass(literal(a: 1, b: 'x'));
}

/**
 * Unpacking depends on runtime array contents, which the handler cannot resolve,
 * so it bails to the reflected (mixed) type rather than guessing a shape.
 */
function literal_unpack_falls_back_to_reflected_type(array $arr): void
{
    $_r = literal(...$arr);
    /** @psalm-check-type-exact $_r = mixed */
}
?>
--EXPECTF--
