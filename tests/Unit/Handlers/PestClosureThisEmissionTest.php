<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Pest\PestClosureThisHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the `tests/Pest.php` path of {@see PestClosureThisHandler}. psalm-tester
 * `.phpt` files run from temp files outside any `tests/` tree, so only the in-file `uses()` path is
 * reachable there. This forks a real Psalm over a fixture project whose `tests/Pest.php` maps
 * `Feature/` to a custom TestCase: the Feature test reads a protected member of it (clean, and its
 * `@psalm-check-type-exact` assertions hold), while the Unit test falls back to PHPUnit's TestCase
 * and gets the one expected `UndefinedThisPropertyFetch`.
 */
#[CoversClass(PestClosureThisHandler::class)]
final class PestClosureThisEmissionTest extends TestCase
{
    #[Test]
    public function binds_the_pest_php_test_case_per_directory(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/PestClosureThis',
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \is_string($finding['type'] ?? null) && \is_string($finding['message'] ?? null)) {
                $findings[] = $finding['type'] . ': ' . $finding['message'];
            }
        }

        $this->assertSame(
            ['UndefinedThisPropertyFetch: Instance property PHPUnit\Framework\TestCase::$featureOnly is not defined'],
            $findings,
        );
    }
}
