--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// Blade auto-escapes {{ $var }} via htmlspecialchars(), so passing user input as
// view data is NOT an html taint sink — no TaintedHtml should be reported.
function renderWithHelper(\Illuminate\Http\Request $request, \Illuminate\View\View $view): void {
    view('profile', ['name' => $request->input('name')]);
    view('profile', [], ['name' => $request->input('name')]);
    $view->with('name', $request->input('name'));
    $view->with(['name' => $request->input('name')]);
}
?>
--EXPECTF--
