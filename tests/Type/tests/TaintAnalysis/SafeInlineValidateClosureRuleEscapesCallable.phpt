--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * A `@psalm-taint-escape` docblock on a closure rule literal inside an inline
 * validate() array removes the named kind from the validated field, exactly as
 * the class-level annotation does for a custom Rule class.
 *
 * The input() result is bound to a variable before `new`: the direct form
 * `new ($request->input('k'))()` is a separate known false negative and would
 * make this test pass vacuously. No `(string)` cast either — a cast currently
 * drops the escape for Rule classes too, which would also hide the feature.
 *
 * The --threads=1 in --ARGS-- mirrors
 * SafeInlineValidateCustomRuleEscapesHeaderViaVariable: parallel-worker graph
 * merges can drop removed_taints on the variable-indirection edge.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedMethodCall
 */
function store(Request $request): object {
    $request->validate([
        'dataTable' => ['required', 'string',
            /** @psalm-taint-escape callable */
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
