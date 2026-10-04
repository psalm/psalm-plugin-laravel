<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `bin/ci/select-apps.php` parses the body of a privileged `issue_comment`
 * (`/psalm-delta <tokens>`), so these cases pin the grammar and, above all,
 * that only registry-allowlisted values ever reach the workflow outputs.
 */
final class SelectAppsTest extends TestCase
{
    private const REGISTRY = [
        'groups' => [
            'default' => 'Default set',
            'octane' => 'Octane apps',
            'filament' => 'Filament apps',
            'lib' => 'Libraries',
        ],
        'apps' => [
            ['name' => 'monica', 'repo' => 'https://example.test/monica.git', 'ref' => 'aaaaaaa', 'php' => '8.4', 'groups' => ['default']],
            ['name' => 'solidtime', 'repo' => 'https://example.test/solidtime.git', 'ref' => 'bbbbbbb', 'php' => '8.4', 'groups' => ['default', 'octane', 'filament']],
            ['name' => 'filament', 'repo' => 'https://example.test/filament.git', 'ref' => 'ccccccc', 'php' => '8.4', 'groups' => ['filament', 'lib'], 'timeout' => 20],
            ['name' => 'unit3d', 'repo' => 'https://example.test/unit3d.git', 'ref' => 'ddddddd', 'php' => '8.4', 'groups' => ['octane']],
            ['name' => 'm3u-editor', 'repo' => 'https://example.test/m3u.git', 'ref' => 'eeeeeee', 'php' => '8.4', 'groups' => ['filament']],
            ['name' => 'vito', 'repo' => 'https://example.test/vito.git', 'ref' => 'fffffff', 'php' => '8.4'],
        ],
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function runSelections(): iterable
    {
        yield 'bare command selects default' => ['/psalm-delta', 'monica,solidtime', 'default'];
        yield 'group adds to default' => ['/psalm-delta octane', 'monica,solidtime,unit3d', 'default + octane'];
        yield 'app name adds to default' => ['/psalm-delta vito', 'monica,solidtime,vito', 'default + vito'];
        yield 'all selects every app' => ['/psalm-delta all', 'monica,solidtime,filament,unit3d,m3u-editor,vito', 'all'];
        yield 'group wins over same-named app' => ['/psalm-delta filament', 'monica,solidtime,filament,m3u-editor', 'default + filament'];
        yield 'commas, case and extra spaces' => ['/psalm-delta  OCTANE,Vito ,', 'monica,solidtime,unit3d,vito', 'default + octane + vito'];
        yield 'only the first line counts (CRLF)' => ["/psalm-delta octane\r\nall vito", 'monica,solidtime,unit3d', 'default + octane'];
    }

    #[Test]
    #[DataProvider('runSelections')]
    public function itResolvesARunSelection(string $comment, string $appsCsv, string $label): void
    {
        $result = $this->select($comment);

        $this->assertSame('run', $result['status']);
        $this->assertSame($appsCsv, $result['apps_csv']);
        $this->assertSame($label, $result['label']);
        $this->assertSame('', $result['reply']);
        $this->assertSame(\explode(',', $appsCsv), \array_column($result['matrix']['include'], 'name'));
    }

    #[Test]
    public function matrixEntriesKeepRegistryFieldsButDropGroups(): void
    {
        $include = $this->select('/psalm-delta filament')['matrix']['include'];

        $expected = self::REGISTRY['apps'][2];
        unset($expected['groups']);
        $this->assertSame($expected, $include[2]);
        $this->assertArrayNotHasKey('groups', $include[0]);
    }

    #[Test]
    public function argvSelectorUsesTheSameGrammarWithoutThePrefix(): void
    {
        $result = $this->select(null, 'octane,vito');

        $this->assertSame('run', $result['status']);
        $this->assertSame('monica,solidtime,unit3d,vito', $result['apps_csv']);
    }

    #[Test]
    public function helpStartsNoRunAndListsGroupsWithTheirApps(): void
    {
        $result = $this->select('/psalm-delta help');

        $this->assertSame('help', $result['status']);
        $this->assertSame([], $result['matrix']['include']);
        $this->assertSame('', $result['apps_csv']);
        foreach (['default', 'octane', 'filament', 'lib', 'unit3d', 'm3u-editor', 'vito'] as $token) {
            $this->assertStringContainsString($token, $result['reply']);
        }
    }

    #[Test]
    public function unknownTokenStartsNoRunAndSuggestsTheClosestToken(): void
    {
        $result = $this->select('/psalm-delta octane vitto');

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['matrix']['include']);
        $this->assertSame('', $result['apps_csv']);
        $this->assertStringContainsString('`vitto`', $result['reply']);
        $this->assertStringContainsString('`vito`', $result['reply']);
    }

    /** @return iterable<string, array{string}> */
    public static function nonTriggers(): iterable
    {
        yield 'longer command' => ['/psalm-deltax'];
        yield 'longer command with tokens' => ['/psalm-deltax all'];
        yield 'command not first' => ['please /psalm-delta all'];
    }

    #[Test]
    #[DataProvider('nonTriggers')]
    public function itIgnoresCommentsThatAreNotTheCommand(string $comment): void
    {
        $result = $this->select($comment);

        $this->assertSame('ignore', $result['status']);
        $this->assertSame([], $result['matrix']['include']);
        $this->assertSame('', $result['reply']);
    }

    /** @return iterable<string, array{string}> */
    public static function injectionTokens(): iterable
    {
        yield 'command substitution' => ['/psalm-delta $(touch_pwn)'];
        yield 'backticks' => ['/psalm-delta `pwn_tick`'];
        yield 'quotes' => ['/psalm-delta "pwn\'quote'];
        yield 'yq expression' => ['/psalm-delta octane .apps[]|pwn'];
        yield 'oversized token' => ['/psalm-delta ' . \str_repeat('pwn', 20)];
    }

    #[Test]
    #[DataProvider('injectionTokens')]
    public function itRejectsInjectionLikeTokensWithoutEchoingThem(string $comment): void
    {
        $result = $this->select($comment);

        $this->assertSame('error', $result['status']);
        $this->assertSame('', $result['apps_csv']);
        $this->assertSame('', $result['label']);
        $this->assertStringNotContainsString('pwn', $result['reply']);
    }

    /**
     * @return array{status: string, apps_csv: string, label: string, matrix: array{include: list<array<string, mixed>>}, reply: string}
     */
    private function select(?string $comment, ?string $selector = null): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/ci/select-apps.php'];
        if ($selector !== null) {
            $command[] = $selector;
        }

        $env = \getenv();
        unset($env['COMMENT_BODY']);
        if ($comment !== null) {
            $env['COMMENT_BODY'] = $comment;
        }

        $process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($process);
        \fwrite($pipes[0], \json_encode(self::REGISTRY, \JSON_THROW_ON_ERROR));
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($process), $stderr);

        /** @var array{status: string, apps_csv: string, label: string, matrix: array{include: list<array<string, mixed>>}, reply: string} */
        return \json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
    }
}
