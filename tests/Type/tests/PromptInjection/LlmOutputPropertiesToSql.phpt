--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\LlmOutputProperties;

// One function per TAINTED_PROPERTIES entry (or per class within the shared `text` entry),
// each expected to raise exactly one TaintedSql. Findings come out in source order, so a
// missing line's position identifies the property that stopped being a source.

function stepText(\Laravel\Ai\Responses\AgentResponse $response): void {
    foreach ($response->steps as $step) {
        \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $step->text);
    }
}

function gatewayStepText(\Laravel\Ai\Gateway\StepResponse $step): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $step->text);
}

function transcriptionSegmentText(\Laravel\Ai\Responses\Data\TranscriptionSegment $segment): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $segment->text);
}

function textResponseReasoning(\Laravel\Ai\Responses\TextResponse $response): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM audit WHERE note = ' . $response->reasoning);
}

function streamTextDelta(\Laravel\Ai\Responses\StreamableAgentResponse $stream): void {
    foreach ($stream as $event) {
        if ($event instanceof \Laravel\Ai\Streaming\Events\TextDelta) {
            \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $event->delta);
        }
    }
}

function choiceAnswerChoice(\Laravel\Ai\Responses\Data\ChoiceAnswer $answer): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM topics WHERE slug = ' . $answer->choice);
}

function scoreAnswerLegend(\Laravel\Ai\Responses\Data\ScoreAnswer $answer): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM levels WHERE legend = ' . \serialize($answer->legend));
}

function classificationResponseAnswers(\Laravel\Ai\Responses\ClassificationResponse $response): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM topics WHERE answers = ' . \serialize($response->answers));
}

function persistAnswer(\Laravel\Ai\Responses\Data\Answer $answer): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM topics WHERE answer = ' . \serialize($answer));
}

function classificationResponseAnswerMethod(\Laravel\Ai\Responses\ClassificationResponse $response): void {
    persistAnswer($response->answer('topic'));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
