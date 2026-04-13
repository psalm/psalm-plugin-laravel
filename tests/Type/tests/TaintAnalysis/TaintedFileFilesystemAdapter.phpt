--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * FilesystemAdapter methods that accept a user-controlled path
 * are file-taint sinks — an attacker could read, overwrite, or
 * delete arbitrary files via path traversal.
 */

function storageGet(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->get($request->input('path'));
}

function storagePut(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->put($request->input('path'), 'contents');
}

function storageDownload(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->download($request->input('path'));
}

function storageDelete(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->delete($request->input('path'));
}

function storageCopy(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->copy($request->input('from'), $request->input('to'));
}

function storageMove(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->move($request->input('from'), $request->input('to'));
}

function storageMakeDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->makeDirectory($request->input('dir'));
}

function storageDeleteDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->deleteDirectory($request->input('dir'));
}

function storageJson(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->json($request->input('path'));
}

function storageResponse(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->response($request->input('path'));
}

function storageServe(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->serve($request, $request->input('path'));
}

function storagePutFile(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->putFile($request->input('path'), 'file');
}

function storagePutFileAs(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->putFileAs($request->input('path'), 'file', $request->input('name'));
}

function storagePrepend(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->prepend($request->input('path'), 'data');
}

function storageAppend(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->append($request->input('path'), 'data');
}

function storageReadStream(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->readStream($request->input('path'));
}

function storageWriteStream(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $stream = tmpfile();
    assert($stream !== false);
    $fs->writeStream($request->input('path'), $stream);
}

function storageTemporaryUploadUrl(\Illuminate\Http\Request $request, \Illuminate\Filesystem\FilesystemAdapter $fs): void {
    $fs->temporaryUploadUrl($request->input('path'), new \DateTimeImmutable('+1 hour'));
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
