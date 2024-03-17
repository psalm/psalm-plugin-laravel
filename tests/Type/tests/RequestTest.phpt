--FILE--
<?php declare(strict_types=1);

use \Illuminate\Http\Request;

function input_returns_mixed_by_default(Request $request): mixed
{
  return $request->input('foo', false);
}
?>
--EXPECTF--
