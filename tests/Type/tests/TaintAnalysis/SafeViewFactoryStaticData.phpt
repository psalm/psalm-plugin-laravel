--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderStaticView(\Illuminate\View\Factory $factory): void {
    $factory->make('welcome', ['title' => 'Hello World']);
    $factory->file('/views/about.blade.php', ['version' => '1.0']);
}
?>
--EXPECTF--

