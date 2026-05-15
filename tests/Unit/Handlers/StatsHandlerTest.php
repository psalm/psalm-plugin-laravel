<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\StatsHandler;

#[CoversClass(StatsHandler::class)]
final class StatsHandlerTest extends TestCase
{
    // Typed `mixed` because $_SERVER['argv'] is not guaranteed to be an array —
    // the test suite itself exercises non-array argv cases (via $this->argvProvider),
    // and a previous test could leave a non-array value behind. A narrower type
    // would TypeError in setUp() in that scenario.
    private mixed $originalArgv = null;

    private bool $argvWasSet = false;

    protected function setUp(): void
    {
        $this->argvWasSet = \array_key_exists('argv', $_SERVER);
        $this->originalArgv = $this->argvWasSet ? $_SERVER['argv'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->argvWasSet) {
            $_SERVER['argv'] = $this->originalArgv;
        } else {
            unset($_SERVER['argv']);
        }
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function argvProvider(): iterable
    {
        yield 'stats flag present' => [['psalm', '--stats'], true];
        yield 'stats flag present among other flags' => [['psalm', '--no-cache', '--stats', '--threads=4'], true];
        yield 'no stats flag' => [['psalm', '--no-cache'], false];
        yield 'empty argv' => [[], false];
        yield 'argv not set' => [null, false];
        yield 'argv is non-array' => ['--stats', false];
        // Psalm defines --stats as a boolean longopt with no `=value` form,
        // so `--stats=1` is not a valid invocation and must not match.
        yield 'stats with value is not a match' => [['psalm', '--stats=1'], false];
    }

    #[Test]
    #[DataProvider('argvProvider')]
    public function stats_requested_reflects_argv(mixed $argv, bool $expected): void
    {
        if ($argv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $argv;
        }

        $method = new \ReflectionMethod(StatsHandler::class, 'statsRequested');

        $this->assertSame($expected, $method->invoke(null));
    }
}
