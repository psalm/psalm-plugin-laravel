--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace Shadow {
    /**
     * A userland function that merely shares the name does not escape anything, even when it
     * forwards its argument like the core stub does.
     *
     * @psalm-flow ($value) -> return
     */
    function json_encode(string $value, int $flags = 0): string {
        return $value . $flags;
    }

    function render(\Illuminate\Http\Request $request): void {
        $v = (string) $request->input('v');

        echo json_encode($v, 15);
    }
}

namespace Plain {
    function render(\Illuminate\Http\Request $request): void {
        $v = (string) $request->input('v');

        // No shadow in this namespace: the unqualified call falls back to the global function.
        echo json_encode($v, 15);
        echo \json_encode($v, 15);

        // A fully qualified call to some other namespace's function is not the core function either.
        echo \Shadow\json_encode($v, 15);
    }
}

namespace Aliased {
    use function Unscanned\renamed as json_encode;

    function render(\Illuminate\Http\Request $request): void {
        $v = (string) $request->input('v');

        // The alias target is unknown to Psalm, which then resolves the name to the core function
        // and keeps the finding; the handler must not strip it just because no userland function exists.
        echo json_encode($v, 15);
    }
}

namespace Relative {
    use function json_encode;

    /**
     * @psalm-flow ($value) -> return
     */
    function json_encode(string $value, int $flags = 0): string {
        return $value . $flags;
    }

    function render(\Illuminate\Http\Request $request): void {
        $v = (string) $request->input('v');

        // PHP ignores `use function` for a namespace-relative call, so this is the userland function above.
        echo namespace\json_encode($v, 15);
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
