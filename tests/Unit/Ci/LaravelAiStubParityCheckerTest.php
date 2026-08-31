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
        use Laravel\Ai\Responses\QueuedAgentResponse;
        use Laravel\Ai\Responses\StreamableAgentResponse;
        use Laravel\Ai\Gateway\FakeTextGateway;
        use Closure;

        trait Promptable
        {
            public static function make(mixed ...$arguments): static {}

            public function prompt(
                Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): AgentResponse {}

            public function stream(
                Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): StreamableAgentResponse {}

            public function queue(
                Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): QueuedAgentResponse {}

            public function broadcast(
                Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                bool $now = false,
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): StreamableAgentResponse {}

            public function broadcastNow(
                Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): StreamableAgentResponse {}

            public function broadcastOnQueue(
                Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): QueuedAgentResponse {}

            protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array {}
            protected function getDefaultModelFor(\Laravel\Ai\Contracts\Providers\TextProvider $provider): string {}
            protected function getTimeout(?int $timeout): int {}

            public static function fake(Closure|array $responses = []): FakeTextGateway {}
            public static function assertPrompted(Closure|string $callback): void {}
            public static function assertPromptedTimes(int $times = 1): void {}
            public static function assertNotPrompted(Closure|string $callback): void {}
            public static function assertNeverPrompted(): void {}
            public static function assertQueued(Closure|string $callback): void {}
            public static function assertNotQueued(Closure|string $callback): void {}
            public static function assertNeverQueued(): void {}
            public static function isFaked(): bool {}
        }
        PHP;

    private const CLEAN_REQUEST_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai\Tools;

        class Request
        {
            protected function data(mixed $key = null, mixed $default = null): mixed {}
        }
        PHP;

    private const CLEAN_STREAMABLE_RESPONSE_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai\Responses;

        class StreamableAgentResponse
        {
            protected function syncConversationFromStreamedResponse(): void {}
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

    #[Test]
    public function by_reference_drift_fails(): void
    {
        $stub = \str_replace('Decisions|string $prompt,', 'Decisions|string &$prompt,', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('by-reference metadata differs', $output);
    }

    #[Test]
    public function variadic_drift_fails(): void
    {
        $stub = \str_replace('Decisions|string $prompt,', 'Decisions|string ...$prompt,', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('variadic metadata differs', $output);
    }

    #[Test]
    public function default_value_drift_fails(): void
    {
        $stub = \str_replace('?int $timeout = null,', '?int $timeout = 30,', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('default/optionality differs', $output);
    }

    #[Test]
    public function a_vendor_only_public_method_fails(): void
    {
        $stub = \str_replace("public static function assertNeverQueued(): void {}\n", '', self::CLEAN_STUB);

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('assertNeverQueued(): public method exists', $output);
    }

    #[Test]
    public function a_vendor_only_protected_promptable_method_fails(): void
    {
        $stub = \str_replace(
            "    protected function getTimeout(?int \$timeout): int {}\n",
            '',
            self::CLEAN_STUB,
        );

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('getTimeout(): protected method exists', $output);
    }

    #[Test]
    public function a_vendor_only_protected_request_method_fails(): void
    {
        $stub = \str_replace(
            "    protected function data(mixed \$key = null, mixed \$default = null): mixed {}\n",
            '',
            self::CLEAN_REQUEST_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('data(): protected method exists', $output);
    }

    #[Test]
    public function a_vendor_only_protected_streamable_response_method_fails(): void
    {
        $stub = \str_replace(
            "    protected function syncConversationFromStreamedResponse(): void {}\n",
            '',
            self::CLEAN_STREAMABLE_RESPONSE_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['StreamableAgentResponse.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('syncConversationFromStreamedResponse(): protected method exists', $output);
    }

    /** @return array{int, string} exit code and combined output */
    private function check(string $stubSource): array
    {
        return $this->checkFiles(['Promptable.phpstub' => $stubSource]);
    }

    /**
     * @param array<string, string> $stubs
     * @return array{int, string} exit code and combined output
     */
    private function checkFiles(array $stubs): array
    {
        $stubsDir = \sys_get_temp_dir() . '/psalm-laravel-ai-parity-' . \bin2hex(\random_bytes(6));
        \mkdir($stubsDir, 0o777, true);
        foreach ($stubs as $file => $stubSource) {
            \file_put_contents($stubsDir . '/' . $file, $stubSource);
        }

        $command = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 3) . '/bin/ci/check-laravel-ai-stub-parity.php')
            . ' ' . \escapeshellarg($stubsDir)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);

        foreach ($stubs as $file => $_) {
            \unlink($stubsDir . '/' . $file);
        }

        \rmdir($stubsDir);

        return [$exitCode, \implode("\n", $output)];
    }
}
