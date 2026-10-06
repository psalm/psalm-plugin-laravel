<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards `bin/ci/check-laravel-ai-stub-parity.php`: every other test type-checks against the stubs, so a gap in
 * the checker is invisible. Each case mutates a copy of a real stub (the shipped stubs are the clean baseline,
 * since the checker exits 0 on them) and asserts what the checker says.
 *
 * Skipped without laravel/ai, like the checker itself (exit 2).
 */
final class LaravelAiStubParityCheckerTest extends TestCase
{
    private const APPROVAL_STUB = <<<'PHP'
        <?php

        namespace Laravel\Ai\Tools;

        use Laravel\Ai\Approvals\Approval;
        use Laravel\Ai\Concerns\InteractsWithApprovals;
        use Laravel\Ai\Contracts\Approvable;
        use Laravel\Ai\Contracts\Tool;
        use Laravel\Ai\Providers\Tools\ProviderTool;

        /**
         * @since %1$s implements \Laravel\Ai\Contracts\Approvable
         */
        class ToolNameResolver implements Approvable
        {
            use InteractsWithApprovals;

            public static function resolve(Tool|ProviderTool $tool): string {}

            /**
             * @since %2$s
             */
            protected function needsApproval(Request $request): Approval|bool {}
        }
        PHP;

    protected function setUp(): void
    {
        if (!\trait_exists(\Laravel\Ai\Promptable::class)) {
            $this->markTestSkipped('needs laravel/ai (optional integration, not in composer.json)');
        }
    }

    /**
     * @param list<string> $contains
     * @param list<string> $notContains
     */
    #[Test]
    #[DataProvider('scenarios')]
    public function the_checker_reports(string $stub, int $expectedExit, array $contains = [], array $notContains = []): void
    {
        $dir = \sys_get_temp_dir() . '/psalm-laravel-ai-parity-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/stub.phpstub', $stub);

        $output = [];
        $exitCode = 0;
        \exec(
            \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 3) . '/bin/ci/check-laravel-ai-stub-parity.php')
            . ' ' . \escapeshellarg($dir) . ' 2>&1',
            $output,
            $exitCode,
        );

        \unlink($dir . '/stub.phpstub');
        \rmdir($dir);
        $output = \implode("\n", $output);

        $this->assertSame($expectedExit, $exitCode, $output);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $output);
        }

        foreach ($notContains as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    /** @return iterable<string, array{string, int, list<string>, list<string>}> */
    public static function scenarios(): iterable
    {
        $prompt = 'AgentInput|UserMessage|Decisions|string $prompt,';

        yield 'clean Promptable' => [self::stub('Promptable'), 0, [], []];
        yield 'object default matching by class' => [self::stub('PendingStep'), 0, [], []];
        // The real class also implements Responsable; the implied Traversable must not count as a gap.
        yield 'IteratorAggregate implies Traversable' => [self::stub('Responses/StreamableAgentResponse'), 0, [], []];
        yield 'full interface clause' => [self::stub('Tools/Request'), 0, [], []];
        yield 'Stringable is implicit with __toString()' => [
            self::mutate('Responses/TextResponse', 'implements \Stringable', ''),
            0,
            [],
            [],
        ];

        yield 'missing trailing parameter' => [
            self::mutate('Promptable', "        ?int \$timeout = null,\n", ''),
            1,
            ['stub declares 4 parameter(s)', 'installed laravel/ai declares 5'],
            [],
        ];
        // `@psalm-taint-sink llm_prompt $prompt` binds by name, so a rename leaves the sink pointing at nothing.
        yield 'renamed parameter' => [
            self::mutate('Promptable', $prompt, 'AgentInput|UserMessage|Decisions|string $text,'),
            1,
            ['is named "$text" in the stub, "$prompt" in the installed laravel/ai'],
            [],
        ];
        yield 'changed parameter type' => [
            self::mutate('Promptable', $prompt, 'string $prompt,'),
            1,
            ['stub says "string"'],
            [],
        ];
        yield 'iterable union narrowed to array' => [
            self::mutate('Promptable', 'Closure|iterable $tools', 'Closure|array $tools'),
            1,
            ['Laravel\Ai\Promptable::withTools($tools): stub says "Closure|array"'],
            [],
        ];
        yield 'by-reference parameter' => [
            self::mutate('Promptable', $prompt, 'AgentInput|UserMessage|Decisions|string &$prompt,'),
            1,
            ['by-reference metadata differs'],
            [],
        ];
        yield 'variadic parameter' => [
            self::mutate('Promptable', $prompt, 'AgentInput|UserMessage|Decisions|string ...$prompt,'),
            1,
            ['variadic metadata differs'],
            [],
        ];
        yield 'changed scalar default' => [
            self::mutate('Promptable', '?int $timeout = null,', '?int $timeout = 30,'),
            1,
            ['default/optionality differs'],
            [],
        ];
        yield 'object default of a different class' => [
            self::mutate('PendingStep', 'new TextUsage,', 'new \stdClass,'),
            1,
            ['Laravel\Ai\PendingStep::__construct(): parameter at position 10 default/optionality differs'],
            [],
        ];
        yield 'object default dropped' => [
            self::mutate('PendingStep', ' = new TextUsage,', ','),
            1,
            ['Laravel\Ai\PendingStep::__construct(): parameter at position 10 default/optionality differs'],
            [],
        ];

        yield 'vendor-only public method' => [
            self::mutate('Promptable', 'public static function assertNeverQueued(): void {}', ''),
            1,
            ['assertNeverQueued(): public method exists'],
            [],
        ];
        yield 'vendor-only protected method' => [
            self::mutate('Promptable', 'protected function getTimeout(?int $timeout): int {}', ''),
            1,
            ['getTimeout(): protected method exists'],
            [],
        ];
        yield 'vendor-only property' => [
            self::mutate('Promptable', 'protected ?array $adHocMessages = null;', ''),
            1,
            ['adHocMessages: public/protected property exists'],
            [],
        ];

        yield 'interface missing from the stub' => [
            self::mutate('Tools/Request', 'implements Arrayable, ArrayAccess', 'implements ArrayAccess'),
            1,
            ['Request: implements Illuminate\Contracts\Support\Arrayable in the installed laravel/ai, but the stub\'s `implements` clause omits it'],
            [],
        ];
        yield 'stale interface in the stub' => [
            self::mutate('Tools/Request', 'implements Arrayable, ArrayAccess', 'implements Arrayable, ArrayAccess, \Countable'),
            1,
            ["Request: stub's `implements` clause declares Countable, but the installed class doesn't implement it"],
            [],
        ];

        // `@since` on a method and on an `implements` line gate independently, each strictly while the
        // installed release is older than the tag.
        foreach ([[true, true], [false, false], [true, false], [false, true]] as [$interfaceAhead, $methodAhead]) {
            $interfaceFinding = "ToolNameResolver: stub's `implements` clause declares Laravel\\Ai\\Contracts\\Approvable, but the installed class doesn't implement it";
            $methodFinding = 'needsApproval(): declared in the stub but not found on the installed class';
            $interfaceGated = 'ToolNameResolver implements Laravel\Ai\Contracts\Approvable (@since 999.0.0)';
            $methodGated = 'ToolNameResolver::needsApproval() (@since 999.0.0)';

            yield \sprintf('@since interface %s, method %s', $interfaceAhead ? 'ahead' : 'due', $methodAhead ? 'ahead' : 'due') => [
                \sprintf(self::APPROVAL_STUB, $interfaceAhead ? '999.0.0' : '0.0.1', $methodAhead ? '999.0.0' : '0.0.1'),
                $interfaceAhead && $methodAhead ? 0 : 1,
                [$interfaceAhead ? $interfaceGated : $interfaceFinding, $methodAhead ? $methodGated : $methodFinding],
                [$interfaceAhead ? $interfaceFinding : 'implements Laravel\Ai\Contracts\Approvable (@since', $methodAhead ? $methodFinding : 'needsApproval() (@since'],
            ];
        }

        yield '@since interface tag does not exempt another stale interface' => [
            \str_replace('implements Approvable', 'implements Approvable, \Countable', \sprintf(self::APPROVAL_STUB, '999.0.0', '999.0.0')),
            1,
            ["clause declares Countable, but the installed class doesn't implement it"],
            ['declares Laravel\Ai\Contracts\Approvable'],
        ];

        // A class absent from the installed release is skipped before reflection and not counted when gated.
        yield '@since class ahead' => [
            self::missingClassStub('@since 999.0.0'),
            0,
            ['Version-gated', 'Laravel\Ai\NotYetShipped (@since 999.0.0)', 'Compared 0 method/function signatures across 0 classes'],
            ['declared in'],
        ];
        foreach ([
            '@since class due' => '@since 0.0.1',
            'untagged class' => null,
            'interface-clause tag is not a class tag' => '@since 999.0.0 implements \Countable',
            'non-numeric @since version' => '@since 999.0.0-beta',
        ] as $name => $tag) {
            yield $name => [self::missingClassStub($tag), 1, ['Laravel\Ai\NotYetShipped: declared in'], ['Version-gated']];
        }
    }

    private static function stub(string $relativePath): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . "/stubs/integrations/laravel-ai/{$relativePath}.phpstub");
    }

    /** Fails loudly when the shipped stub no longer contains `$search`, instead of silently testing nothing. */
    private static function mutate(string $relativePath, string $search, string $replace): string
    {
        $source = self::stub($relativePath);
        if (!\str_contains($source, $search)) {
            throw new \LogicException("{$relativePath}.phpstub no longer contains `{$search}`; update this test.");
        }

        return \str_replace($search, $replace, $source);
    }

    private static function missingClassStub(?string $sinceLine): string
    {
        $tag = $sinceLine === null ? '' : "\n * {$sinceLine}";

        return <<<PHP
            <?php

            namespace Laravel\\Ai;

            /**
             * A class a later laravel/ai minor introduces.
             *{$tag}
             */
            final class NotYetShipped
            {
                public function decide(string \$question): mixed {}
            }
            PHP;
    }
}
