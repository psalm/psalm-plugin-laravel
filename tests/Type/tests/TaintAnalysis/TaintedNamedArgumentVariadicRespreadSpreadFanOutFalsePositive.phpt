--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicRespreadSpreadFanOutFalsePositive;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Trait shape from lorisleiva/laravel-actions: a static entry point re-spreads its variadic
 * arguments onto an instance method — reproducing #1395's original report.
 */
trait AsObject
{
    public static function run(mixed ...$arguments): mixed
    {
        return (new static())->handle(...$arguments);
    }
}

final class ListChangelogEntriesAction
{
    use AsObject;

    /** @return list<\Symfony\Component\Finder\SplFileInfo> */
    public function handle(?string $directory = null, int $page = 1): array
    {
        $directory ??= '/changelog';
        echo $page;

        return array_values(File::files($directory));
    }
}

/**
 * The reported #1395 false positive, DELIBERATELY NOT suppressed.
 *
 * `run(page: ...)` writes its only argument at offset 0, which is `run`'s variadic `$arguments`'s
 * own declared index, so `getParameterOffset()` keys that node correctly and the plugin preserves
 * it. The `TaintedFile` below is born one hop later, where `handle(...$arguments)` fans the spread
 * out across `$directory` and `$page` — ordinary spread imprecision with no named argument
 * involved. `forward(...['page' => $p])` produces the byte-identical finding with no named `Arg`
 * node anywhere in the file, which is the proof that the spelling is not the cause.
 *
 * The handler used to hide this by killing the source flow at the call site. That also killed the
 * genuine finding when the named argument's true destination is itself a sink
 * ({@see TaintedNamedArgumentVariadicRespreadGenuineDestinationReports.phpt}), so suppressing the
 * named spelling of a spelling-independent false positive was a bad trade. The imprecision belongs
 * to Psalm's spread handling and is recorded as an upstream limitation in `docs/security.md`.
 */
function controllerAction(Request $request): mixed
{
    return ListChangelogEntriesAction::run(page: (string) $request->input('page'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of TaintedNamedArgumentVariadicRespreadSpreadFanOutFalsePositive\ListChangelogEntriesAction::handle cannot be mixed, expecting null|string
MixedArgument on line %d: Argument 2 of TaintedNamedArgumentVariadicRespreadSpreadFanOutFalsePositive\ListChangelogEntriesAction::handle cannot be mixed, expecting int
TaintedFile on line %d: Detected tainted file handling
