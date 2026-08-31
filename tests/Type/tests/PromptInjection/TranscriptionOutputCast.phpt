--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.11.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
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
    return $transcription->segments->count() + $transcription->usage->promptTokens;
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
