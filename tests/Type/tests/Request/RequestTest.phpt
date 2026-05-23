--FILE--
<?php declare(strict_types=1);

use \Illuminate\Http\Request;

function input_returns_mixed_by_default(Request $request): mixed
{
  return $request->input('foo', false);
}

function testConditionalReturnTypes(Request $request): void
{
    // input() with no args -> array, with key -> mixed
    $_allInput = $request->input();
    /** @psalm-check-type-exact $_allInput = array<string, mixed> */

    $_singleInput = $request->input('key');
    /** @psalm-check-type-exact $_singleInput = mixed */

    // query() with no args -> array of string|array, with key -> string|array|null
    $_allQuery = $request->query();
    /** @psalm-check-type-exact $_allQuery = array<string, array<array-key, mixed>|string> */

    $_singleQuery = $request->query('key');
    /** @psalm-check-type-exact $_singleQuery = array<array-key, mixed>|string|null */

    $_queryWithDefault = $request->query('theme', '');
    /** @psalm-check-type-exact $_queryWithDefault = array<array-key, mixed>|string */

    // post() with no args -> array of string|array, with key -> string|array|null
    $_allPost = $request->post();
    /** @psalm-check-type-exact $_allPost = array<string, array<array-key, mixed>|string> */

    $_singlePost = $request->post('key');
    /** @psalm-check-type-exact $_singlePost = array<array-key, mixed>|string|null */

    $_postWithDefault = $request->post('name', 'anon');
    /** @psalm-check-type-exact $_postWithDefault = array<array-key, mixed>|string */
}
?>
--EXPECTF--
