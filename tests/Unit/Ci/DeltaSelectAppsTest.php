<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the flag half of `bin/ci/delta-select-apps.php`: `/psalm-delta` comments are untrusted, and the flags
 * it emits run PR code in CI, so only flags declared in the registry may reach the output.
 */
final class DeltaSelectAppsTest extends TestCase
{
    private const REGISTRY = [
        'groups' => ['default' => 'Baseline', 'blade' => 'Blade-heavy apps'],
        'flags' => ['--blade' => 'Blade on', '--no-blade' => 'Blade off'],
        'apps' => [
            ['name' => 'alpha', 'groups' => ['default']],
            ['name' => 'beta', 'groups' => ['blade']],
            ['name' => 'gamma', 'groups' => []],
        ],
    ];

    #[Test]
    #[DataProvider('runs')]
    public function a_run_carries_the_declared_flags_in_comment_order(string $selector, string $apps, string $flags): void
    {
        $selection = $this->select(self::REGISTRY, $selector);

        $this->assertSame('run', $selection['status']);
        $this->assertSame($apps, $selection['apps_csv']);
        $this->assertSame($flags, $selection['flags']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function runs(): iterable
    {
        yield 'group plus flag' => ['blade --blade', 'alpha,beta', '--blade'];
        yield 'flag alone keeps default' => ['--blade', 'alpha', '--blade'];
        yield 'comma separated' => ['blade,--blade', 'alpha,beta', '--blade'];
        yield 'all plus flag' => ['all --no-blade', 'alpha,beta,gamma', '--no-blade'];
        // psalm-laravel analyze is last-wins on --blade/--no-blade, so the order must survive.
        yield 'order preserved' => ['--no-blade blade --blade', 'alpha,beta', '--no-blade --blade'];
        yield 'no flag' => ['blade', 'alpha,beta', ''];
    }

    /** @param array<string, mixed> $registry */
    #[Test]
    #[DataProvider('rejections')]
    public function a_flag_outside_the_registry_starts_no_run(array $registry, string $selector, string $reply): void
    {
        $selection = $this->select($registry, $selector);

        $this->assertSame('error', $selection['status']);
        $this->assertSame('', $selection['flags']);
        $this->assertSame('', $selection['apps_csv']);
        $this->assertStringContainsString($reply, (string) $selection['reply']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function rejections(): iterable
    {
        yield 'typo gets a hint' => [self::REGISTRY, 'blade --blad', '`--blad` Did you mean `--blade`?'];
        yield 'undeclared psalm flag' => [self::REGISTRY, '--set-baseline=x', '- `--set-baseline=x`'];
        yield 'unsafe flag not echoed' => [self::REGISTRY, '--$(id)', '(not echoed)'];
        yield 'help never mixes with flags' => [self::REGISTRY, 'help --blade', '`help` works only on its own'];
        yield 'registry declares no flags' => [
            \array_diff_key(self::REGISTRY, ['flags' => true]),
            '--blade',
            'Flags: none declared.',
        ];
    }

    #[Test]
    public function a_malformed_declared_flag_fails_the_registry(): void
    {
        $registry = self::REGISTRY;
        $registry['flags'] = ['--blade; id' => 'injected'];

        [$exitCode, $stdout, $stderr] = $this->execute($registry, 'blade');

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertStringContainsString('invalid flag', $stderr);
    }

    /**
     * @param array<string, mixed> $registry
     * @return array<string, mixed>
     */
    private function select(array $registry, string $selector): array
    {
        [$exitCode, $stdout, $stderr] = $this->execute($registry, $selector);
        $this->assertSame(0, $exitCode, $stderr);

        $selection = \json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($selection);

        /** @var array<string, mixed> $selection */
        return $selection;
    }

    /**
     * @param array<string, mixed> $registry
     * @return array{int, string, string}
     */
    private function execute(array $registry, string $selector): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/ci/delta-select-apps.php', $selector],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        \fwrite($pipes[0], \json_encode($registry, \JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $stdout, $stderr];
    }
}
