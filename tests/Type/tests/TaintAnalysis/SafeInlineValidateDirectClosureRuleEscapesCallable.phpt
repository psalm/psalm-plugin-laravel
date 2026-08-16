--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Laravel accepts a bare closure as a field's whole rule, without the wrapping
 * array. The annotation must be honoured there too.
 *
 * This shape also pins the other half of the doc-comment read: PhpParser
 * attaches a comment to whichever node starts at its offset, and in keyed
 * position (`'field' => /* doc *\/ closure`) the array item starts back at the
 * key token, so the comment lands on the closure node rather than on the item.
 * The list-style fixtures pin the item-node half.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedMethodCall
 */
function storeDirectClosure(Request $request): object {
    $request->validate([
        'dataTable' =>
            /** @psalm-taint-escape callable */
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === $attribute) {
                    $fail('The selected data table is invalid.');
                }
            },
    ]);

    $dataTableClass = $request->input('dataTable');

    return new $dataTableClass();
}
?>
--EXPECTF--
