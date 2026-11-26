--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// Known limitation of the relation-name sinks (caveat in the Eloquent\Builder::with() stub docblock).
// Laravel reads extra positional arguments as more relation names (`func_get_args()`), but the
// stubs mark these methods `@psalm-variadic` without a parameter to bind them to, so Psalm has no
// parameter sink for them: a tainted name in the second (or later) position is not reported.
// Builder::with() is the exception for the second position, whose `$callback` carries the sink.
// Flip to TaintedCallable once these arguments are sunk. The control routes the same input
// through a per-file sink, so the silence above is not a source that never reached anything.

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class RnvAuthor extends Model {}

function extraRelationNames(Request $request, RnvAuthor $author): void
{
    $author->load('comments', (string) $request->input('include'));
    RnvAuthor::with('comments', (string) $request->input('include'));
    RnvAuthor::query()->withCount('comments', (string) $request->input('include'));
}

/** @psalm-taint-sink callable $name */
function rnvSink(string $name): bool { return $name !== ''; }

function rnvControl(Request $request): void
{
    rnvSink((string) $request->input('include'));
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
