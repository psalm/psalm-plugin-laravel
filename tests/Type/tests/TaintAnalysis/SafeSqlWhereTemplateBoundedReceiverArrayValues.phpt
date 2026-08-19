--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A receiver typed as a `@template T of Builder` is a `TTemplateParam` atomic, not a
 * `TNamedObject` — `isLaravelBuilder` rejected it exactly like it rejected `TNull` until it
 * started recursing into the template's `as` bound to decide whether it is builder-only. #1336
 *
 * @template T of \Illuminate\Database\Query\Builder
 *
 * @param T $q
 */
function safeTemplateBoundedReceiverArrayWhere(\Illuminate\Http\Request $request, $q): void {
    $q->where(['status_id' => $request->input('status')]);
}
?>
--EXPECTF--
