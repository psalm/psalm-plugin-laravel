--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\TranscriptCasts;

function logTranscriptCast(\Laravel\Ai\Responses\TranscriptionResponse $transcription): void {
    // TranscriptionResponse::__toString() returns $text verbatim upstream, so the
    // cast is the same trust boundary as the property read. Covering the property
    // in the handler does not cover this: the cast resolves through the stub.
    \Illuminate\Support\Facades\DB::select((string) $transcription);
}

function transcriptSurfaceSurvivesRedeclaration(\Laravel\Ai\Responses\TranscriptionResponse $transcription): int {
    // The stub re-declares the class, which resets its member list. Reading the
    // members it is not annotating proves the restatement is complete.
    return $transcription->segments->count() + \strlen($transcription->text) + \strlen((string) $transcription) + (int) ($transcription->meta instanceof \Laravel\Ai\Responses\Data\Meta);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
