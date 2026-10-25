--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Collection::first(), get(), last(), pull(), value() and LazyCollection::get()
 * carry `@psalm-flow ($default) -> return` and are @psalm-taint-specialize: a
 * tainted default at one callsite must not pool into the return node and
 * poison the same method on an unrelated collection (issue #2071).
 *
 * Psalm 7 does not specialize stub methods on its own, so without the
 * annotation every echo below reports TaintedHtml.
 */

/** @param \Illuminate\Support\Collection<int, string> $collection */
function taintedFirst(\Illuminate\Support\Collection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->first(null, $request->input('x'));
}

/** @param \Illuminate\Support\Collection<int, string> $collection */
function safeFirst(\Illuminate\Support\Collection $collection): void {
    echo $collection->first(null, 'fallback');
}

/** @param \Illuminate\Support\Collection<string, string> $collection */
function taintedGet(\Illuminate\Support\Collection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->get('key', $request->input('x'));
}

/** @param \Illuminate\Support\Collection<string, string> $collection */
function safeGet(\Illuminate\Support\Collection $collection): void {
    echo $collection->get('key', 'fallback');
}

/** @param \Illuminate\Support\Collection<int, string> $collection */
function taintedLast(\Illuminate\Support\Collection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->last(null, $request->input('x'));
}

/** @param \Illuminate\Support\Collection<int, string> $collection */
function safeLast(\Illuminate\Support\Collection $collection): void {
    echo $collection->last(null, 'fallback');
}

/** @param \Illuminate\Support\Collection<string, string> $collection */
function taintedPull(\Illuminate\Support\Collection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->pull('key', $request->input('x'));
}

/** @param \Illuminate\Support\Collection<string, string> $collection */
function safePull(\Illuminate\Support\Collection $collection): void {
    echo $collection->pull('key', 'fallback');
}

/** @param \Illuminate\Support\Collection<int, array{name: string}> $collection */
function taintedValue(\Illuminate\Support\Collection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->value('name', $request->input('x'));
}

/** @param \Illuminate\Support\Collection<int, array{name: string}> $collection */
function safeValue(\Illuminate\Support\Collection $collection): void {
    echo (string) $collection->value('name', 'fallback');
}

/** @param \Illuminate\Support\LazyCollection<string, string> $collection */
function taintedLazyGet(\Illuminate\Support\LazyCollection $collection, \Illuminate\Http\Request $request): mixed {
    return $collection->get('key', $request->input('x'));
}

/** @param \Illuminate\Support\LazyCollection<string, string> $collection */
function safeLazyGet(\Illuminate\Support\LazyCollection $collection): void {
    echo $collection->get('key', 'fallback');
}
?>
--EXPECTF--
