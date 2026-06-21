<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Encryption\EncrypterTaintHandler;
use Symfony\Component\Process\Process;

/**
 * Narrowing-shadow regression (sibling of #1113): methods reached only through `app('encrypter')`
 * narrowing must resolve against the real `Illuminate\Encryption\Encrypter`, not report
 * `UndefinedMethod`.
 *
 * Why a real Psalm subprocess instead of a phpt: the bug only manifests under whole-program analysis
 * over a config's `<projectFiles>`. psalm-tester always passes the snippet as an explicit file
 * argument, and in that single-file mode Psalm scans the narrowed-to vendor class anyway — so a phpt
 * resolves the methods with or without the fix and would guard nothing. This points Psalm at a
 * self-contained fixture project the way a real application runs it, where the regression actually
 * reproduces.
 *
 * The fix replaced the full-class `Encryption/Encrypter.phpstub` (which shadowed the whole class to
 * host its four taint methods) with {@see EncrypterTaintHandler}, leaving the real class intact so
 * its methods resolve.
 */
#[CoversClass(EncrypterTaintHandler::class)]
final class EncrypterMethodResolutionTest extends TestCase
{
    #[Test]
    public function it_resolves_encrypter_methods_reached_only_through_container_narrowing(): void
    {
        $findings = $this->runPsalmAndCollectFindings();

        // The regression was a flood of UndefinedMethod on Illuminate\Encryption\Encrypter. None must
        // remain — assert on the class so an unrelated UndefinedMethod elsewhere can't mask it.
        $encrypterUndefined = $this->messagesOfType($findings, 'UndefinedMethod', 'Illuminate\\Encryption\\Encrypter');

        $this->assertSame(
            [],
            $encrypterUndefined,
            "app('encrypter') methods must resolve against the real Encrypter, got UndefinedMethod:\n" . \implode("\n", $encrypterUndefined),
        );

        // The fixture calls *only* app('encrypter')->method(). If a future ContainerResolver change
        // degraded app('encrypter') back to `mixed`, those calls would surface as MixedMethodCall
        // (not UndefinedMethod) and the encrypter taint would silently stop flowing on this exact
        // path — a regression the UndefinedMethod assertion alone cannot see. Any MixedMethodCall
        // here means the narrowing that the handler depends on has broken.
        $mixedCalls = $this->messagesOfType($findings, 'MixedMethodCall');

        $this->assertSame(
            [],
            $mixedCalls,
            "app('encrypter') must narrow to Illuminate\\Encryption\\Encrypter, not mixed, got MixedMethodCall:\n" . \implode("\n", $mixedCalls),
        );
    }

    /**
     * Extract the messages of every finding of a given issue type, optionally restricted to those
     * mentioning $needle. Returns a re-indexed `list<string>` so it compares cleanly against `[]`.
     *
     * @param list<array{type: string, message: string}> $findings
     * @return list<string>
     */
    private function messagesOfType(array $findings, string $type, string $needle = ''): array
    {
        $matching = \array_filter(
            $findings,
            static fn(array $finding): bool => $finding['type'] === $type
                && ($needle === '' || \str_contains($finding['message'], $needle)),
        );

        return \array_values(\array_map(
            static fn(array $finding): string => $finding['message'],
            $matching,
        ));
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function runPsalmAndCollectFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/EncrypterMethodResolution';
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
            if (\is_array($finding) && \is_string($finding['type'] ?? null)) {
                $findings[] = [
                    'type' => $finding['type'],
                    'message' => (string) ($finding['message'] ?? ''),
                ];
            }
        }

        return $findings;
    }
}
