--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Js::from() applies JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
 * escaping html and has_quotes taints. The Blade @js() directive compiles to
 * Js::from($expression)->toHtml().
 */
function renderJsFrom(\Illuminate\Http\Request $request): void {
    /** @var string $name */
    $name = $request->input('name');
    echo \Illuminate\Support\Js::from($name);
}
?>
--EXPECTF--
