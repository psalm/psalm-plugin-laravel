--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function storeByClientExtension(UploadedFile $file): void {
    $name = Str::ulid()->toString() . '.' . $file->getClientOriginalExtension();
    Storage::putFileAs('attendance-proofs', $file, $name);
}
?>
--EXPECTF--
