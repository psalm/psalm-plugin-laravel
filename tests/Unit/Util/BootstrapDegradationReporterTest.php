<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\BootstrapDegradationReporter;
use Psalm\LaravelPlugin\Util\InternalErrorClassifier;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

#[CoversClass(BootstrapDegradationReporter::class)]
#[CoversClass(InternalErrorClassifier::class)]
final class BootstrapDegradationReporterTest extends TestCase
{
    #[Test]
    public function it_warns_with_the_cause_and_remediation_without_claiming_the_plugin_is_disabled(): void
    {
        $output = $this->capturingProgress();

        BootstrapDegradationReporter::warn(
            new \RuntimeException('parse_url(): Argument #1 ($url) must be of type string, null given'),
            $output,
        );

        $joined = \implode("\n", $output->warnings);

        // The signal: names the degraded mode and the originating cause.
        $this->assertStringContainsString('DEGRADED', $joined);
        $this->assertStringContainsString('parse_url(): Argument #1 ($url) must be of type string, null given', $joined);

        // Remediation: points at diagnose and the opt-in to fail instead of degrade.
        $this->assertStringContainsString('psalm-laravel diagnose', $joined);
        $this->assertStringContainsString('failOnInternalError', $joined);

        // Distinct from InternalErrorReporter: a degraded run keeps going, it is NOT disabled.
        $this->assertStringNotContainsStringIgnoringCase('disabled', $joined);
    }

    #[Test]
    public function it_includes_a_classifier_hint_pointing_at_the_fix_site(): void
    {
        $output = $this->capturingProgress();

        // A throwable raised here carries this test file as its origin, so the classifier
        // resolves the "application code" bucket — proving the hint line is wired in.
        BootstrapDegradationReporter::warn(new \LogicException('boom'), $output);

        $joined = \implode("\n", $output->warnings);
        $hint = InternalErrorClassifier::hint(new \LogicException('boom'));

        $this->assertNotNull($hint, 'Expected the classifier to produce a hint for this origin.');
        $this->assertStringContainsString($hint, $joined);
    }

    /**
     * A {@see Progress} double that records every line written through `warning()`.
     *
     * `warning()` is concrete on the base class and delegates to `write()`, so overriding
     * `write()` captures the full rendered line. The remaining abstract members are no-ops.
     *
     * @return Progress&object{warnings: list<string>}
     */
    private function capturingProgress(): Progress
    {
        return new class extends Progress {
            /** @var list<string> */
            public array $warnings = [];

            #[\Override]
            public function write(string $message): void
            {
                $this->warnings[] = $message;
            }

            #[\Override]
            public function debug(string $message): void {}

            #[\Override]
            public function startPhase(Phase $phase, int $threads = 1): void {}

            #[\Override]
            public function expand(int $number_of_tasks): void {}

            #[\Override]
            public function taskDone(int $level): void {}

            #[\Override]
            public function finish(): void {}

            #[\Override]
            public function alterFileDone(string $file_name): void {}
        };
    }
}
