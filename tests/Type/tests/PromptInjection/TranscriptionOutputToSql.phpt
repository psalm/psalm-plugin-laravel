--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Transcripts;

function searchTranscript(\Laravel\Ai\Responses\TranscriptionResponse $transcription): void {
    // The audio was uploaded by a user, so the transcript is attacker-authored text
    // that a speech model merely re-typed. TranscriptionResponse sits in its own
    // hierarchy (it does not extend TextResponse), so LlmOutputTaintHandler needs it
    // listed explicitly for the $text read to carry taint.
    \Illuminate\Support\Facades\DB::select($transcription->text);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
