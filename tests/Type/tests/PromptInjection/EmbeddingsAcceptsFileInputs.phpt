--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
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
