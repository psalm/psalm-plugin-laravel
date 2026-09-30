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
    private const CLEAN_V1_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai;

        use Illuminate\Broadcasting\Channel;
        use Laravel\Ai\Approvals\Decisions;
        use Laravel\Ai\Contracts\AgentInput;
        use Laravel\Ai\Contracts\Providers\TextProvider;
        use Laravel\Ai\Enums\Lab;
        use Laravel\Ai\Gateway\FakeTextGateway;
        use Laravel\Ai\Messages\UserMessage;
        use Laravel\Ai\Responses\AgentResponse;
        use Laravel\Ai\Responses\QueuedAgentResponse;
        use Laravel\Ai\Responses\StreamableAgentResponse;
        use Laravel\SerializableClosure\SerializableClosure;
        use Closure;

        trait Promptable
        {
            protected ?array $adHocMessages = null;
            protected ?SerializableClosure $runtimeTools = null;

            public static function make(...$arguments): static {}

            public function prompt(
                AgentInput|UserMessage|Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): AgentResponse {}

            public function stream(
                AgentInput|UserMessage|Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): StreamableAgentResponse {}

            public function queue(
                AgentInput|UserMessage|Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): QueuedAgentResponse {}

            public function broadcast(
                AgentInput|UserMessage|Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                bool $now = false,
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): StreamableAgentResponse {}

            public function broadcastNow(
                AgentInput|UserMessage|Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): StreamableAgentResponse {}

            public function broadcastOnQueue(
                AgentInput|UserMessage|Decisions|string $prompt,
                Channel|array $channels,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
            ): QueuedAgentResponse {}

            public function withMessages(iterable $messages): static {}
            public function withTools(Closure|iterable $tools): static {}
            protected function resolveAgentTools(): ?array {}
            protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array {}
            protected function getDefaultModelFor(TextProvider $provider): string {}
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

    private const CLEAN_PRE_1_0_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai;

        use Illuminate\Broadcasting\Channel;
        use Laravel\Ai\Approvals\Decisions;
        use Laravel\Ai\Contracts\Providers\TextProvider;
        use Laravel\Ai\Enums\Lab;
        use Laravel\Ai\Gateway\FakeTextGateway;
        use Laravel\Ai\Responses\AgentResponse;
        use Laravel\Ai\Responses\QueuedAgentResponse;
        use Laravel\Ai\Responses\StreamableAgentResponse;
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
            protected function getDefaultModelFor(TextProvider $provider): string {}
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

    private const CLEAN_PENDING_STEP_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai;

        use Laravel\Ai\Gateway\TextGenerationOptions;
        use Laravel\Ai\Responses\Data\TextUsage;

        class PendingStep
        {
            public function __construct(
                public readonly int $number,
                public readonly bool $isFinalStep,
                public readonly string $provider,
                public readonly string $model,
                public readonly ?string $instructions,
                public readonly array $messages,
                public readonly array $tools,
                public readonly ?array $schema,
                public readonly ?TextGenerationOptions $options,
                public readonly array $steps = [],
                public readonly TextUsage $usage = new TextUsage,
                public readonly ?int $timeout = null,
                public readonly ?string $invocationId = null,
            ) {}

            public function isFirstStep(): bool {}
            public function withModel(string $model): self {}
            public function withInstructions(?string $instructions): self {}
            public function withMessages(iterable $messages): self {}
            public function withTools(iterable $tools): self {}
            public function onlyTools(string ...$names): self {}
            public function withoutTools(string ...$names): self {}
            public function withToolChoice(ToolChoice|string|array|null $toolChoice): self {}
            public function withMaxTokens(?int $maxTokens): self {}
            public function withProviderOptions(array $providerOptions): self {}
            protected function withOptions(TextGenerationOptions $options): self {}
            protected function resolvedOptions(): TextGenerationOptions {}
            protected function with(array $overrides): self {}
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

    private const CLEAN_TEXT_RESPONSE_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai\Responses;

        class TextResponse
        {
            public function __toString(): string {}
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
        [$exitCode, $output] = $this->check($this->cleanPromptableStub());

        $this->assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function a_stub_missing_a_trailing_parameter_fails(): void
    {
        $stub = \str_replace("        ?int \$timeout = null,\n", '', $this->cleanPromptableStub());

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
        $stub = \str_replace($this->promptParameter('$prompt'), $this->promptParameter('$text'), $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('is named "$text" in the stub, "$prompt" in the installed laravel/ai', $output);
    }

    #[Test]
    public function a_drifted_parameter_type_still_fails(): void
    {
        $stub = \str_replace($this->promptParameter('$prompt'), 'string $prompt,', $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('stub says "string"', $output);
    }

    #[Test]
    public function an_iterable_union_parameter_matching_the_installed_package_does_not_drift(): void
    {
        $this->requireV1AgentInput();

        [$exitCode, $output] = $this->check($this->cleanPromptableStub());

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringNotContainsString('Laravel\Ai\Promptable::withTools($tools)', $output);
    }

    #[Test]
    public function a_changed_iterable_union_parameter_type_still_drifts(): void
    {
        $this->requireV1AgentInput();

        $stub = \str_replace('Closure|iterable $tools', 'Closure|array $tools', $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Laravel\Ai\Promptable::withTools($tools): stub says "Closure|array"', $output);
    }

    #[Test]
    public function by_reference_drift_fails(): void
    {
        $stub = \str_replace($this->promptParameter('$prompt'), $this->promptParameter('&$prompt'), $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('by-reference metadata differs', $output);
    }

    #[Test]
    public function variadic_drift_fails(): void
    {
        $stub = \str_replace($this->promptParameter('$prompt'), $this->promptParameter('...$prompt'), $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('variadic metadata differs', $output);
    }

    #[Test]
    public function default_value_drift_fails(): void
    {
        $stub = \str_replace('?int $timeout = null,', '?int $timeout = 30,', $this->cleanPromptableStub());

        [$exitCode, $output] = $this->check($stub);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('default/optionality differs', $output);
    }

    #[Test]
    public function a_matching_object_default_does_not_drift(): void
    {
        $this->requirePendingStep();

        [$exitCode, $output] = $this->checkFiles(['PendingStep.phpstub' => self::CLEAN_PENDING_STEP_STUB]);

        $this->assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function an_object_default_with_a_different_class_drifts(): void
    {
        $this->requirePendingStep();

        $stub = \str_replace('new TextUsage,', 'new \stdClass,', self::CLEAN_PENDING_STEP_STUB);

        [$exitCode, $output] = $this->checkFiles(['PendingStep.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Laravel\Ai\PendingStep::__construct(): parameter at position 10 default/optionality differs', $output);
    }

    #[Test]
    public function an_object_default_that_disappears_drifts(): void
    {
        $this->requirePendingStep();

        $stub = \str_replace(
            'public readonly TextUsage $usage = new TextUsage,',
            'public readonly TextUsage $usage,',
            self::CLEAN_PENDING_STEP_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['PendingStep.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Laravel\Ai\PendingStep::__construct(): parameter at position 10 default/optionality differs', $output);
    }

    #[Test]
    public function a_vendor_only_public_method_fails(): void
    {
        $stub = \str_replace("public static function assertNeverQueued(): void {}\n", '', $this->cleanPromptableStub());

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
            $this->cleanPromptableStub(),
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

    #[Test]
    public function an_at_since_tagged_method_ahead_of_the_installed_version_is_not_drift(): void
    {
        $stub = \str_replace(
            "    protected function data(mixed \$key = null, mixed \$default = null): mixed {}\n",
            <<<'PHP'
                protected function data(mixed $key = null, mixed $default = null): mixed {}

                /**
                 * @since 999.0.0
                 */
                public function notYetShipped(): void {}
            PHP,
            self::CLEAN_REQUEST_STUB,
        );

        [, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        // CLEAN_REQUEST_STUB is a minimal fixture (only declares data()), so
        // it always drifts against the real class regardless of the gate;
        // assert the gate suppressed its own finding, not overall exit code.
        $this->assertStringContainsString('notYetShipped() (@since 999.0.0)', $output);
        $this->assertStringNotContainsString('notYetShipped(): declared in the stub but not found on the installed class', $output);
    }

    /**
     * The tag only exempts a method while the installed release predates it.
     * Once the requirement is satisfied and the method still isn't there,
     * that is a real rename/removal and must fail like any other drift.
     */
    #[Test]
    public function an_at_since_tagged_method_already_due_still_fails(): void
    {
        $stub = \str_replace(
            "    protected function data(mixed \$key = null, mixed \$default = null): mixed {}\n",
            <<<'PHP'
                protected function data(mixed $key = null, mixed $default = null): mixed {}

                /**
                 * @since 0.0.1
                 */
                public function neverShipped(): void {}
            PHP,
            self::CLEAN_REQUEST_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('neverShipped(): declared in the stub but not found on the installed class', $output);
    }

    #[Test]
    public function a_stub_declaring_every_real_interface_has_no_interface_gap(): void
    {
        $stub = \str_replace(
            'class Request',
            'class Request implements \Illuminate\Contracts\Support\Arrayable, \ArrayAccess',
            self::CLEAN_REQUEST_STUB,
        );

        [, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        $this->assertStringNotContainsString('Laravel\Ai\Tools\Request: implements', $output);
    }

    #[Test]
    public function a_stub_missing_a_real_interface_fails(): void
    {
        $stub = \str_replace(
            'class Request',
            'class Request implements \ArrayAccess',
            self::CLEAN_REQUEST_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString(
            'Request: implements Illuminate\Contracts\Support\Arrayable in the installed laravel/ai, but the stub\'s `implements` clause omits it',
            $output,
        );
    }

    #[Test]
    public function a_stub_declaring_a_stale_interface_fails(): void
    {
        $stub = \str_replace(
            'class Request',
            'class Request implements \Illuminate\Contracts\Support\Arrayable, \ArrayAccess, \Countable',
            self::CLEAN_REQUEST_STUB,
        );

        [$exitCode, $output] = $this->checkFiles(['Request.phpstub' => $stub]);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString(
            "Request: stub's `implements` clause declares Countable, but the installed class doesn't implement it",
            $output,
        );
    }

    /**
     * `IteratorAggregate` extends `Traversable`, so Reflection reports both
     * for a class that only writes `implements IteratorAggregate`. The
     * checker must not treat the implied `Traversable` as a gap.
     */
    #[Test]
    public function an_interface_extending_another_does_not_flag_the_implied_parent(): void
    {
        $stub = \str_replace(
            'class StreamableAgentResponse',
            'class StreamableAgentResponse implements \IteratorAggregate',
            self::CLEAN_STREAMABLE_RESPONSE_STUB,
        );

        [, $output] = $this->checkFiles(['StreamableAgentResponse.phpstub' => $stub]);

        // The real class implements more than just IteratorAggregate (e.g.
        // Responsable), so other "implements" findings are expected here —
        // only the implied Traversable must not be one of them.
        $this->assertStringNotContainsString('implements Traversable', $output);
    }

    /**
     * PHP grants `Stringable` implicitly to any class declaring
     * `__toString()`, on the real class and (were it loadable) the stub
     * alike — it never needs to be written in an `implements` clause.
     */
    #[Test]
    public function a_class_with_tostring_is_not_flagged_for_implicit_stringable(): void
    {
        [, $output] = $this->checkFiles(['TextResponse.phpstub' => self::CLEAN_TEXT_RESPONSE_STUB]);

        $this->assertStringNotContainsString('Laravel\Ai\Responses\TextResponse: implements', $output);
    }

    private function cleanPromptableStub(): string
    {
        // AgentInput is an interface: class_exists() returns false for one.
        return $this->laravelAiIsV1()
            ? self::CLEAN_V1_STUB
            : self::CLEAN_PRE_1_0_STUB;
    }

    private function promptParameter(string $parameter): string
    {
        $type = $this->laravelAiIsV1()
            ? 'AgentInput|UserMessage|Decisions|string'
            : 'Decisions|string';

        return "{$type} {$parameter},";
    }

    private function requireV1AgentInput(): void
    {
        if (!$this->laravelAiIsV1()) {
            $this->markTestSkipped('needs Laravel\Ai\Contracts\AgentInput (the withTools() iterable-union parity capability)');
        }
    }

    /** The widened prompt input union laravel/ai 1.0 introduced, probed by capability rather than version. */
    private function laravelAiIsV1(): bool
    {
        return \interface_exists(\Laravel\Ai\Contracts\AgentInput::class);
    }

    private function requirePendingStep(): void
    {
        if (!\class_exists(\Laravel\Ai\PendingStep::class)) {
            $this->markTestSkipped('needs Laravel\Ai\PendingStep (the object-default parity capability)');
        }
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
