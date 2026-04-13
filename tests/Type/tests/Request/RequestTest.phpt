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

    // query() with no args -> array, with key -> mixed
    $_allQuery = $request->query();
    /** @psalm-check-type-exact $_allQuery = array<string, mixed> */

    $_singleQuery = $request->query('key');
    /** @psalm-check-type-exact $_singleQuery = mixed */

    // post() with no args -> array, with key -> mixed
    $_allPost = $request->post();
    /** @psalm-check-type-exact $_allPost = array<string, mixed> */

    $_singlePost = $request->post('key');
    /** @psalm-check-type-exact $_singlePost = mixed */
}
?>
--EXPECTF--
