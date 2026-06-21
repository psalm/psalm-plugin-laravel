--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: the precise file()/allFiles() return-narrowing stub
// (declared on Illuminate\Http\Request) does not apply on Laravel 11; Psalm
// falls back to Laravel's coarser native return types. Tracked as an L11
// stub-narrowing gap, not a missing Laravel feature.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Locks in the conditional return on `Request::file($key)`:
 *
 *     $request->file()         // array<string, UploadedFile|array<UploadedFile>>
 *     $request->file('avatar') // UploadedFile|array<UploadedFile>|null
 *
 * The conditional is declared on the Request class stub (not on the
 * InteractsWithInput trait stub) because Psalm 7 collapses conditional
 * returns when the override hits a trait. See issue #925.
 */
final class RequestFileTest
{
    public function noArgFormReturnsArray(Request $request): void
    {
        $r = $request->file();
        /** @psalm-check-type-exact $r = array<string, UploadedFile|array<array-key, UploadedFile>> */;
        unset($r);
    }

    public function stringKeyFormReturnsNullableUnion(Request $request): void
    {
        $r = $request->file('avatar');
        /** @psalm-check-type-exact $r = UploadedFile|array<array-key, UploadedFile>|null */;
        unset($r);
    }

    public function foreachOverNoArgFormYieldsNonNullEntries(Request $request): void
    {
        foreach ($request->file() as $file) {
            /** @psalm-check-type-exact $file = UploadedFile|array<array-key, UploadedFile> */;
            unset($file);
        }
    }

    public function allFilesMatchesNoArgShape(Request $request): void
    {
        $r = $request->allFiles();
        /** @psalm-check-type-exact $r = array<string, UploadedFile|array<array-key, UploadedFile>> */;
        unset($r);
    }

    /**
     * Flow-sensitive narrowing: when $key is `string|null`, an `is null` check
     * should pick the matching conditional branch in each arm.
     */
    public function variableKeyNarrowsPerBranch(Request $request, ?string $key): void
    {
        if (null === $key) {
            $r = $request->file($key);
            /** @psalm-check-type-exact $r = array<string, UploadedFile|array<array-key, UploadedFile>> */;
            unset($r);
        } else {
            $r = $request->file($key);
            /** @psalm-check-type-exact $r = UploadedFile|array<array-key, UploadedFile>|null */;
            unset($r);
        }
    }

    /**
     * FormRequest inherits Request — the conditional survives subclassing,
     * which is the realistic surface for issue #925 (controllers receive a
     * FormRequest, not the base Request, in most apps).
     */
    public function formRequestSubclassPreservesNarrowing(FormRequest $request): void
    {
        $all = $request->file();
        /** @psalm-check-type-exact $all = array<string, UploadedFile|array<array-key, UploadedFile>> */;
        unset($all);

        $single = $request->file('avatar');
        /** @psalm-check-type-exact $single = UploadedFile|array<array-key, UploadedFile>|null */;
        unset($single);
    }

    /**
     * `hasFile()`-style narrowing: after `file($key)`, an `is_array($f)` check
     * splits the union into the array form (the array<UploadedFile> branch)
     * and the singular-or-null branch. This mirrors how Laravel's own
     * `Request::hasFile()` is implemented and how userland code reads back
     * a single-or-multi file upload.
     */
    public function isArrayCheckSplitsUnion(Request $request): void
    {
        $f = $request->file('attachments');
        if (is_array($f)) {
            /** @psalm-check-type-exact $f = array<array-key, UploadedFile> */;
            unset($f);
        } else {
            /** @psalm-check-type-exact $f = UploadedFile|null */;
            unset($f);
        }
    }
}
?>
--EXPECTF--
