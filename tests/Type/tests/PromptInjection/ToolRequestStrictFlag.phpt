--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
if (\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled()) {
    return;
}
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.40.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// Tools\Request takes its casts from InteractsWithData, so Laravel 13.40's `$strict` flag reaches it
// with the framework version, not the laravel/ai one.

function toolStrictStringIsTainted(\Laravel\Ai\Tools\Request $request): void {
    \Illuminate\Support\Facades\DB::delete('DELETE FROM users WHERE name = ' . (string) $request->string('q', null, true));
}

function toolStrictIntegerIsSafe(\Laravel\Ai\Tools\Request $request): void {
    \Illuminate\Support\Facades\DB::delete('DELETE FROM users WHERE id = ' . $request->integer('id', 0, true));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
