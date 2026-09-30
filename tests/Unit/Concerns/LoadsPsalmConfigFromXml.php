<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Concerns;

use Psalm\Config;

/**
 * `Config::loadFromXML()` reads the global `$argv` as Psalm CLI paths
 * (`Config::loadFromXMLElement()` -> `CliUtils::getPathsToCheck(null)`): every
 * non-option argument is added to `<projectFiles>`, and a nonexistent one
 * `exit(1)`s the process. Test-runner argv is not Psalm argv: ParaTest's
 * worker passes `--test-result-file <path-not-yet-created>`, which kills the
 * worker mid-test. Hiding the arguments keeps the loaded config independent of
 * how the suite was invoked.
 *
 * @see \Psalm\Config::loadFromXMLElement() `global $argv` -> `CliUtils::getPathsToCheck()` exits on a missing path
 */
trait LoadsPsalmConfigFromXml
{
    private static function loadPsalmConfigFromXml(string $baseDir, string $xml): Config
    {
        global $argv;

        /** @var list<string>|null $originalArgv */
        $originalArgv = $argv;
        $argv = \array_slice($originalArgv ?? [], 0, 1);

        try {
            return Config::loadFromXML($baseDir, $xml);
        } finally {
            $argv = $originalArgv;
        }
    }
}
