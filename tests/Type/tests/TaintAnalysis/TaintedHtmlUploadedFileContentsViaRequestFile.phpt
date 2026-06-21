--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Dropping the object-level taint source from Request::file() (issue #1134)
 * must NOT weaken detection of the genuinely user-controlled data: the file
 * *contents* are still attacker-supplied. Echoing them is tainted HTML.
 *
 * The taint here comes from UploadedFile::get() being its own source (see
 * stubs/common/Http/UploadedFile.phpstub), independent of how the object was
 * obtained. This locks in that obtaining it through file() still flows.
 */
function renderUploadContents(\Illuminate\Http\Request $request): void {
    $file = $request->file('avatar');
    if ($file instanceof \Illuminate\Http\UploadedFile) {
        echo $file->get();
    }
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
