--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Filesystem methods that accept a user-controlled path
 * are file-taint sinks — an attacker could read, overwrite,
 * delete, or traverse the filesystem via path traversal.
 */

function fsGet(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->get($request->input('path'));
}

function fsRelativeLink(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->relativeLink($request->input('target'), $request->input('link'));
}

function fsType(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->type($request->input('path'));
}

function fsMimeType(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->mimeType($request->input('path'));
}

function fsGuessExtension(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->guessExtension($request->input('path'));
}

function fsHasSameHash(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->hasSameHash($request->input('first'), $request->input('second'));
}

function fsSize(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->size($request->input('path'));
}

function fsLastModified(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->lastModified($request->input('path'));
}

function fsGlob(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->glob($request->input('pattern'));
}

function fsFiles(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->files($request->input('dir'));
}

function fsAllFiles(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->allFiles($request->input('dir'));
}

function fsDirectories(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->directories($request->input('dir'));
}

function fsEnsureDirectoryExists(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->ensureDirectoryExists($request->input('path'));
}

function fsMoveDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->moveDirectory($request->input('from'), $request->input('to'));
}

function fsCopyDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->copyDirectory($request->input('dir'), $request->input('dest'));
}

function fsDeleteDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->deleteDirectory($request->input('dir'));
}

function fsDeleteDirectories(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->deleteDirectories($request->input('dir'));
}

function fsCleanDirectory(\Illuminate\Http\Request $request, \Illuminate\Filesystem\Filesystem $fs): void {
    $fs->cleanDirectory($request->input('dir'));
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
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
