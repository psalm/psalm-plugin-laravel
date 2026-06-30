<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for #1113: methods reached only through `auth('web')` narrowing must resolve
 * against the real `Illuminate\Auth\SessionGuard`, not report `UndefinedMethod`.
 *
 * Why a real Psalm subprocess instead of a phpt: the bug only manifests under whole-program
 * analysis over a config's `<projectFiles>`. psalm-tester always passes the snippet as an explicit
 * file argument, and in that single-file mode Psalm scans the narrowed-to vendor class anyway — so
 * a phpt resolves the methods with or without the fix and would guard nothing. This points Psalm at
 * a self-contained fixture project the way a real application runs it, where the regression
 * actually reproduces.
 *
 * The fix replaced the full-class `SessionGuard.phpstub` / `TokenGuard.phpstub` (which shadowed the
 * whole class to host one taint method) with {@see GuardTaintHandler}, leaving the real classes
 * intact so their methods resolve.
 */
#[CoversClass(GuardTaintHandler::class)]
final class GuardMethodResolutionTest extends TestCase
{
    #[Test]
    public function it_resolves_session_guard_methods_reached_only_through_auth_narrowing(): void
    {
        $undefinedMethodMessages = $this->runPsalmAndCollectUndefinedMethodMessages();
        $joined = \implode("\n", $undefinedMethodMessages);

        // The regression was a flood of UndefinedMethod on Illuminate\Auth\SessionGuard. None must
        // remain — assert on the class so an unrelated UndefinedMethod elsewhere can't mask it.
        $guardErrors = \array_filter(
            $undefinedMethodMessages,
            static fn(string $message): bool => \str_contains($message, 'Illuminate\\Auth\\SessionGuard'),
        );

        $this->assertSame(
            [],
            \array_values($guardErrors),
            "auth('web') methods must resolve against the real SessionGuard, got UndefinedMethod:\n{$joined}",
        );
    }

    /**
     * @return list<string>
     */
    private function runPsalmAndCollectUndefinedMethodMessages(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/GuardMethodResolution';
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

        $messages = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && ($finding['type'] ?? null) === 'UndefinedMethod') {
                $messages[] = (string) ($finding['message'] ?? '');
            }
        }

        return $messages;
    }
}
