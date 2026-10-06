--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\EmbeddingsInputs;

use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * `Embeddings::for()` takes `array<int, string|Audio|Document|Image|Video>`
 * upstream. The stub narrowed it to `string[]`, which made every file input a
 * false `InvalidArgument`. Native types match on both sides (`array`), so the
 * stub-versus-vendor parity checker cannot catch a docblock-only narrowing of
 * this shape; this fixture is the guard instead.
 */
function embedMixedCorpus(): void {
    Embeddings::for([
        'a plain string input',
        Document::fromString('# Handbook', 'text/markdown'),
        Image::fromBase64('aGk=', 'image/png'),
    ]);
}
?>
--EXPECTF--
