--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderWithData(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory) {
    $bio = $request->input('bio');
    $factory->file('/views/profile.blade.php', ['bio' => $bio]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
