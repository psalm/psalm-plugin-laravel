--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Control for SafeInlineValidateClosureRuleEscapesCallable: identical shape with
 * no docblock on the closure rule. A closure without an annotation contributes
 * no escape, so the taint reaches the dynamic-class sink.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedMethodCall
 */
function storeUnannotated(Request $request): object {
    $request->validate([
        'dataTable' => ['required', 'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === $attribute) {
                    $fail('The selected data table is invalid.');
                }
            },
        ],
    ]);

    $dataTableClass = $request->input('dataTable');

    return new $dataTableClass();
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
