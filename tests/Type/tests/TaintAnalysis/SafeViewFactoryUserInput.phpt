--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// Blade auto-escapes {{ $var }} via htmlspecialchars(), so passing user input as
// view data is NOT an html taint sink — no TaintedHtml should be reported.
function renderProfile(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $factory->make('profile', ['name' => $request->input('name')]);
    $factory->make('profile', [], ['name' => $request->input('name')]);
    $factory->file('/views/profile.blade.php', ['name' => $request->input('name')]);
    $factory->file('/views/profile.blade.php', [], ['name' => $request->input('name')]);
    $factory->first(['profile', 'fallback'], ['name' => $request->input('name')]);
    $factory->first(['profile', 'fallback'], [], ['name' => $request->input('name')]);
    $factory->renderWhen(true, 'profile', ['name' => $request->input('name')]);
    $factory->renderWhen(true, 'profile', [], ['name' => $request->input('name')]);
    $factory->renderUnless(false, 'profile', ['name' => $request->input('name')]);
    $factory->renderUnless(false, 'profile', [], ['name' => $request->input('name')]);
    $factory->renderEach('row', [$request->input('item')], 'item');
    $factory->share('siteName', $request->input('site'));
    $factory->share(['siteName' => $request->input('site')]);
}
?>
--EXPECTF--
