--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Laravel declares get() itself, and Symfony 8 no longer has it to inherit a source from. It returns
 * a request attribute, a query parameter or a body field. Laravel 13 deprecates it.
 */
function search(\Illuminate\Http\Request $request): void {
    echo (string) $request->get('q');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
DeprecatedMethod on line %d: The method Illuminate\Http\Request::get has been marked as deprecated
