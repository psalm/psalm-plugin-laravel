--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.40.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\ValidatedInput;

// The 13.40 stubs that add `$strict` restate the str()/string() taint source.

function request_strict_str(Request $request): void
{
    echo $request->str('name', null, true);
}

/** @param ValidatedInput<\Illuminate\Foundation\Http\FormRequest> $input */
function validated_input_strict_string(ValidatedInput $input): void
{
    echo $input->string('name', null, true);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
