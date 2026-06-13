--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Locks in the dispatcher registration for
 * `\Illuminate\Routing\ResponseFactory::view($view, $data, $status, $headers)`
 * and its contract sibling. With a non-literal `$view` argument the handler
 * applies the dynamic-name fallback (whole-data sink) just like
 * `Factory::make()`, so the tainted `$_GET['x']` flowing to `bio` surfaces
 * as TaintedHtml. A registration regression would leave this call site
 * un-sunk and the assertion would fail.
 */
function renderResponse(\Illuminate\Contracts\Routing\ResponseFactory $responseFactory, string $viewName): void {
    /** @var string $tainted */
    $tainted = $_GET['x'];
    $responseFactory->view($viewName, ['bio' => $tainted]);
}
?>
--EXPECTF--
%ATaintedHtml%a
