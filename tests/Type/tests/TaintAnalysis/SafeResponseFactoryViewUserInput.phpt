--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// ResponseFactory::view() passes $data to Factory::make(), which renders through
// Blade's auto-escaping ({{ $var }} → htmlspecialchars). No TaintedHtml should fire.
function responseView(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->view('welcome', ['name' => $request->input('name')]);
}
?>
--EXPECTF--
