<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the guard. `bin/ci/check-laravel-ai-stub-parity.php` is the only thing
 * that compares a laravel/ai stub against the installed package, and a gap in
 * it is invisible: every other test in the repo type-checks against the stub,
 * so a stub that drifted keeps passing. Each case below writes a deliberately
 * broken copy of one stub and asserts the checker fails on it.
 *
 * Skipped without laravel/ai installed, which is the same gate the checker
 * applies itself (it exits 2 as a soft skip).
 */
final class LaravelAiStubParityCheckerTest extends TestCase
{
    private const CLEAN_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai;

        use Illuminate\Broadcasting\Channel;
        use Laravel\Ai\Approvals\Decisions;
        use Laravel\Ai\Enums\Lab;
        use Laravel\Ai\Responses\AgentResponse;

        trait Promptable
        {
            public function prompt(
                Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): AgentResponse {}
        }
        PHP;

    protected function setUp(): void
    {
        if (!\trait_exists(\Laravel\Ai\Promptable::class)) {
            $this->markTestSkipped('needs laravel/ai (optional integration, not in composer.json)');
        }
    }

    #[Test]
    public function a_stub_matching_the_installed_package_passes(): void
    {
        [$exitCode, $output] = $this->check(self::CLEAN_STUB);

        $this->assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function a_stub_missing_a_trailing_parameter_fails(): void
    {
        $stub = \str_replace("        ?int \$timeout = null,\n", '', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('stub declares 4 parameter(s)', $output);
        $this->assertStringContainsString('installed laravel/ai declares 5', $output);
    }

    /**
     * The dangerous half of the same gap: `@psalm-taint-sink llm_prompt $prompt`
     * matches by name, so a rename leaves the annotation parsing fine and
     * pointing at nothing.
     */
    #[Test]
    public function a_renamed_parameter_fails(): void
    {
        $stub = \str_replace('Decisions|string $prompt,', 'Decisions|string $text,', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('is named "$text" in the stub, "$prompt" in the installed laravel/ai', $output);
    }

    #[Test]
    public function a_drifted_parameter_type_still_fails(): void
    {
        $stub = \str_replace('Decisions|string $prompt,', 'string $prompt,', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('stub says "string"', $output);
    }

    /** @return array{int, string} exit code and combined output */
    private function check(string $stubSource): array
    {
        $stubsDir = \sys_get_temp_dir() . '/psalm-laravel-ai-parity-' . \bin2hex(\random_bytes(6));
        \mkdir($stubsDir, 0o777, true);
        \file_put_contents($stubsDir . '/Promptable.phpstub', $stubSource);

        $command = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 3) . '/bin/ci/check-laravel-ai-stub-parity.php')
            . ' ' . \escapeshellarg($stubsDir)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);

        \unlink($stubsDir . '/Promptable.phpstub');
        \rmdir($stubsDir);

        return [$exitCode, \implode("\n", $output)];
    }
}
