--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Known limitation (#2107): receiver taint does not reach Collection lookup returns.
 *
 * The Collection stubs declare only `@psalm-flow ($default) -> return`, so
 * `first()` gets nothing from the tainted collection it is called on. This
 * test used to expect TaintedHtml, but only because the `$default` flow
 * pooled across call sites (#2071), which specialization removed.
 *
 * `Request::collect()` still types the Collection: collect() lives on
 * Illuminate\Support\Traits\InteractsWithData in Laravel 11+ (#823).
 */
function renderCollectRequestData(\Illuminate\Http\Request $request): void {
    echo $request->collect('name')->first();
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed|null, expecting string
