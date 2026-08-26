--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentVariadicRespreadFileFilesReporterShape;

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
 * `run(page: ...)` names its ONLY written argument `page`, at offset 0 — but `run`'s only
 * declared parameter is the variadic `$arguments`, not `$page`, so NamedArgumentTaintHandler
 * strips the value here at the call site, before it can ever re-spread into `handle()`'s
 * `$directory` and mis-report TaintedFile (#1395).
 */
function controllerAction(Request $request): mixed
{
    return ListChangelogEntriesAction::run(page: (string) $request->input('page'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of SafeNamedArgumentVariadicRespreadFileFilesReporterShape\ListChangelogEntriesAction::handle cannot be mixed, expecting null|string
MixedArgument on line %d: Argument 2 of SafeNamedArgumentVariadicRespreadFileFilesReporterShape\ListChangelogEntriesAction::handle cannot be mixed, expecting int
