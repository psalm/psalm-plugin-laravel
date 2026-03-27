--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * url() and temporaryUrl() generate URL strings — they don't perform
 * file operations. Tainted paths must not trigger TaintedFile here.
 */
function storageUrlWithTaintedPath(
    \Illuminate\Http\Request $request,
    \Illuminate\Filesystem\FilesystemAdapter $disk,
): void {
    /** @var string */
    $path = $request->input('path');

    echo $disk->url($path);
    echo $disk->temporaryUrl($path, new \DateTimeImmutable('+1 hour'));
}
?>
--EXPECTF--
