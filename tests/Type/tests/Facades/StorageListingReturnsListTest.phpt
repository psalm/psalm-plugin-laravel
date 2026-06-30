--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/**
 * `Storage::disk()` narrows to the concrete `FilesystemAdapter` (#802), so its
 * directory-listing methods resolve to the adapter's own implementations. Each
 * of `files()` / `allFiles()` / `directories()` / `allDirectories()` builds its
 * result as `listContents(...)->filter(...)->map(fn (StorageAttributes $a) =>
 * $a->path())->toArray()`, where `StorageAttributes::path(): string` and
 * Flysystem's `DirectoryListing::toArray()` is `iterator_to_array(..., false)`
 * (preserve_keys = false → sequential reindex). So the runtime value is provably
 * `list<string>`, not the bare `array` Laravel's own docblock declares.
 *
 * Laravel's `@return array` (and Larastan, which does not narrow these at all)
 * leaves the element type `mixed`, which surfaces as `MixedAssignment` /
 * `MixedArgument` floods on `foreach (Storage::disk(...)->files() as $f)` in
 * real apps. The `list<string>` stub kills those at the source.
 */

function test_files_returns_list_of_strings(): array
{
    $files = Storage::disk('local')->files();
    /** @psalm-check-type-exact $files = list<string> */

    return $files;
}

function test_all_files_returns_list_of_strings(): array
{
    $files = Storage::disk('local')->allFiles();
    /** @psalm-check-type-exact $files = list<string> */

    return $files;
}

function test_directories_returns_list_of_strings(): array
{
    $dirs = Storage::disk('local')->directories();
    /** @psalm-check-type-exact $dirs = list<string> */

    return $dirs;
}

function test_all_directories_returns_list_of_strings(): array
{
    $dirs = Storage::disk('local')->allDirectories();
    /** @psalm-check-type-exact $dirs = list<string> */

    return $dirs;
}

/**
 * The element type is now `string`, so a `foreach` body no longer leaks `mixed`.
 * The loop variable flows into a string sink with no `MixedArgument`.
 */
function test_foreach_over_files_yields_string(): string
{
    $out = '';
    foreach (Storage::disk('local')->files() as $file) {
        /** @psalm-check-type-exact $file = string */
        $out .= $file;
    }

    return $out;
}

?>
--EXPECTF--
