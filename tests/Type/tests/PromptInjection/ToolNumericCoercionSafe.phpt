--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.9.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runToolNumericLookup(\Laravel\Ai\Tools\Request $request): void {
    // The model picked these values, same trust model as Request::input(). integer()/float()
    // are annotated untainted on the stub: an (int)/(float) cast cannot carry SQL syntax.
    $id = $request->integer('id');
    $score = $request->float('score');

    \Illuminate\Support\Facades\DB::delete('DELETE FROM users WHERE id = ' . $id . ' AND score = ' . $score);
}

function runToolStringLookup(\Laravel\Ai\Tools\Request $request): void {
    // string() stays annotated `@psalm-taint-source input`, so this must still fire.
    $needle = (string) $request->string('q');

    \Illuminate\Support\Facades\DB::delete('DELETE FROM users WHERE name = ' . $needle);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
