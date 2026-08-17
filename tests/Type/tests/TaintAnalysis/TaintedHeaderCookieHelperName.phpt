--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedCookieHelperName(\Illuminate\Http\Request $request): void {
    /** @var string $name */
    $name = $request->input('cookie_name');
    cookie($name, 'safe-value');
}
?>
--EXPECTF--
ArgumentTypeCoercion on line %d: Argument 1 of cookie expects non-empty-string|null, but parent type string provided
TaintedHeader on line %d: Detected tainted header
