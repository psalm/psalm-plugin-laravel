--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Known limitation: clientExtension() values are trusted as configured MIME
 * registry entries. See the documented caveat in docs/security.md.
 */
function useGuessedClientExtension(UploadedFile $file): void {
    echo $file->clientExtension();

    $name = Str::ulid()->toString() . '.' . $file->clientExtension();
    Storage::putFileAs('attendance-proofs', $file, $name);
}
?>
--EXPECTF--
