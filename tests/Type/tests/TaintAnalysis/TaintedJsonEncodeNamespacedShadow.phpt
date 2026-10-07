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
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
