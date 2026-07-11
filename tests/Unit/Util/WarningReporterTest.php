<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\WarningReporter;
use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;
use Symfony\Component\Process\Process;

#[CoversClass(WarningReporter::class)]
final class WarningReporterTest extends TestCase
{
    #[Test]
    public function delegates_to_an_active_progress_reporter(): void
    {
        $progress = new class extends Progress {
            public string $output = '';

            #[\Override]
            public function write(string $message): void
            {
                $this->output .= $message;
            }

            #[\Override]
            public function debug(string $message): void {}

            #[\Override]
            public function startPhase(\Psalm\Progress\Phase $phase, int $threads = 1): void {}

            #[\Override]
            public function expand(int $number_of_tasks): void {}

            #[\Override]
            public function taskDone(int $level): void {}

            #[\Override]
            public function finish(): void {}

            #[\Override]
            public function alterFileDone(string $file_name): void {}
        };
        $fallback = $this->memoryStream();

        WarningReporter::emit($progress, 'Laravel plugin: diagnostic', $fallback);

        $this->assertSame("Warning: Laravel plugin: diagnostic\n", $progress->output);
        $this->assertSame('', $this->streamContents($fallback));
    }

    #[Test]
    public function writes_warnings_to_the_fallback_stream_for_void_progress(): void
    {
        $fallback = $this->memoryStream();

        WarningReporter::emit(new VoidProgress(), 'Laravel plugin: degraded analysis', $fallback);

        $this->assertSame(
            "Warning: Laravel plugin: degraded analysis\n",
            $this->streamContents($fallback),
        );
    }

    #[Test]
    public function a_broken_fallback_stream_does_not_replace_the_original_diagnostic(): void
    {
        $fallback = $this->memoryStream();
        \fclose($fallback);

        WarningReporter::emit(new VoidProgress(), 'Laravel plugin: original diagnostic', $fallback);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function plugin_warnings_reach_stderr_when_psalm_runs_without_progress(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/NoProgressWarning';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [
                \PHP_BINARY,
                $psalmBinary,
                '-c',
                'psalm.xml',
                '--no-cache',
                '--threads=1',
                '--no-progress',
                '--output-format=json',
            ],
            $fixtureDir,
        );
        $process->setTimeout(300);
        $process->run();

        $stderr = $process->getErrorOutput();
        $this->assertStringContainsString(
            'Warning: Laravel plugin: application bootstrap failed partway: no-progress warning fixture',
            $stderr,
        );
        $this->assertStringContainsString('Laravel plugin is running in degraded mode', $stderr);

        $stdout = $process->getOutput();
        $this->assertIsArray(
            \json_decode($stdout, true),
            "Psalm did not keep its JSON report on STDOUT.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );
    }

    #[Test]
    public function all_plugin_warnings_are_routed_through_the_reporter(): void
    {
        $sourceRoot = \dirname(__DIR__, 3) . '/src';
        $directCalls = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'WarningReporter.php') {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new \RuntimeException("Could not read {$file->getPathname()}.");
            }

            $tokens = \token_get_all($contents);
            foreach ($tokens as $index => $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }

                $next = $tokens[$index + 1] ?? null;
                if (\is_array($next) && $next[0] === \T_STRING && $next[1] === 'warning') {
                    $directCalls[] = $file->getPathname() . ':' . $token[2];
                }
            }
        }

        $this->assertSame(
            [],
            $directCalls,
            'Plugin warnings must use WarningReporter so --no-progress cannot discard them.',
        );
    }

    /** @return resource */
    private function memoryStream(): mixed
    {
        $stream = \fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Could not open an in-memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function streamContents(mixed $stream): string
    {
        \rewind($stream);
        $contents = \stream_get_contents($stream);

        if ($contents === false) {
            throw new \RuntimeException('Could not read the in-memory stream.');
        }

        return $contents;
    }
}
