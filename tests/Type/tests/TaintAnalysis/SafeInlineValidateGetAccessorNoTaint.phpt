--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `$request->get('key')` reads a field the inline `validate([...])` covered, so the
 * rule's escape applies as it does to `input('key')`: an `in:` whitelist leaves
 * nothing to inject into the ORDER BY. A literal default carries no taint, so it
 * keeps the escape.
 *
 * @psalm-suppress DeprecatedMethod Laravel 13 deprecates get()
 */
function sortChannels(Request $request): void {
    $request->validate([
        'sort' => 'sometimes|string|in:id,title,name',
    ]);

    DB::table('channels')->orderBy((string) $request->get('sort', 'id'))->get();
}
?>
--EXPECTF--
