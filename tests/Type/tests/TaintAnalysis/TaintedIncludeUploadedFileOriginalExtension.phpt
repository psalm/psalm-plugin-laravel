--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function includeByClientExtension(\Illuminate\Http\UploadedFile $file): void {
    /** @psalm-suppress UnresolvableInclude */
    include $file->getClientOriginalExtension() . '.php';
}
?>
--EXPECTF--
TaintedInclude on line %d: Detected tainted code passed to include or similar
