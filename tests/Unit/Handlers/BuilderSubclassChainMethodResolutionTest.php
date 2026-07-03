<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderNativeStaticReturnTypeHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for #1216: a native `: static` return reached through a fluent chain
 * INSIDE a builder method body (not just at an external call-site) must keep the concrete
 * custom-builder subclass, so a later PRIVATE subclass method resolves.
 *
 * Why a real Psalm subprocess instead of a phpt: `tests/Type/tests/Builder/CustomBuilderSubclassChainTest.phpt`
 * only exercises the chain from an external call-site (`Artist::query()->accessible()->...`).
 * psalm-tester always passes the phpt snippet as an explicit file argument, and classes it
 * references (like `App\Builders\ArtistBuilder`) are resolved through the Composer autoloader's
 * reflection fallback rather than scanned as part of `<projectFiles>` — so Psalm never ANALYZES
 * the body of `ArtistBuilder::withUserContext()`, only its call-site usages. koel's actual error
 * site is INSIDE that method body: `$this->accessible()->when(...)->withRatingSubquery()`,
 * chaining through a PRIVATE method reached via `$this`. This points Psalm at a self-contained
 * fixture project with `<projectFiles>` covering `app/`, the way a real application runs it,
 * where the method body is actually analyzed and the regression can reproduce.
 */
#[CoversClass(BuilderNativeStaticReturnTypeHandler::class)]
final class BuilderSubclassChainMethodResolutionTest extends TestCase
{
    #[Test]
    public function it_resolves_the_inside_body_chain_through_a_private_subclass_method(): void
    {
        $findings = $this->runPsalmAndCollectUndefinedMethodFindings();
        $joined = \implode("\n", \array_map(
            static fn(array $finding): string => "{$finding['file_name']} ({$finding['type']}): {$finding['message']}",
            $findings,
        ));

        // Before the fix, the fluent chain inside withUserContext() collapsed to the abstract
        // FavoriteableBuilder parent — so the false finding's MESSAGE names FavoriteableBuilder
        // (the wrong, collapsed receiver), not ArtistBuilder. Filtering by message substring
        // would miss the very bug this test guards. Filter by the file the call site lives in
        // instead, which stays ArtistBuilder.php regardless of what the receiver collapses to.
        $chainErrors = \array_filter(
            $findings,
            static fn(array $finding): bool => \str_contains($finding['file_name'], 'ArtistBuilder.php'),
        );

        $this->assertSame(
            [],
            \array_values($chainErrors),
            "The inside-body chain in ArtistBuilder::withUserContext() must resolve cleanly, got:\n{$joined}",
        );
    }

    /**
     * @return list<array{type: string, message: string, file_name: string}>
     */
    private function runPsalmAndCollectUndefinedMethodFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/BuilderSubclassChain';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $stdout = $process->getOutput();
        $decoded = \json_decode($stdout, true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \in_array($finding['type'] ?? null, ['UndefinedMethod', 'UndefinedMagicMethod'], true)) {
                $findings[] = [
                    'type' => (string) ($finding['type'] ?? ''),
                    'message' => (string) ($finding['message'] ?? ''),
                    'file_name' => (string) ($finding['file_name'] ?? ''),
                ];
            }
        }

        return $findings;
    }
}
