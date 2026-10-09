--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationResponseElementType;

use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;

// The stub redeclares ClassificationResponse, and Psalm replaces the vendor docblock return
// types with the stub's, so the element generics must be restated there.
function iterateResponse(ClassificationResponse $response): array
{
    $rows = [];

    foreach ($response as $answer) {
        /** @psalm-check-type-exact $answer = Answer */
        $rows[] = $answer->toArray();
    }

    return $rows;
}

function collectResponse(ClassificationResponse $response): array
{
    $answers = $response->collect()->all();
    /** @psalm-check-type-exact $answers = array<string, Answer> */

    return $answers;
}
?>
--EXPECTF--
