<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the parts of `bin/ci/delta-report.php` a PR reader acts on: which icon an app gets, when a badge turns red or
 * green, and that the Total row ignores apps that were not compared on both sides.
 */
final class DeltaReportTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/delta-report-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*/*') ?: [] as $file) {
            \unlink($file);
        }

        foreach (\glob($this->dir . '/*') ?: [] as $app) {
            \rmdir($app);
        }

        \rmdir($this->dir);
    }

    #[Test]
    public function an_app_is_iconed_by_which_side_lost_its_report(): void
    {
        $this->side('head-lost', 'base', issues: true, perf: [10.0, 80.0, 100.0]);
        $this->side('head-lost', 'head', crash: true);
        $this->side('base-lost', 'base', crash: true);
        $this->side('base-lost', 'head', issues: true, perf: [10.0, 80.0, 100.0]);
        $this->side('both-lost', 'base', crash: true);
        $this->side('both-lost', 'head', crash: true);
        \mkdir($this->dir . '/not-run');
        $this->side('fine', 'base', issues: true, perf: [10.0, 80.0, 100.0]);
        $this->side('fine', 'head', issues: true, perf: [10.0, 80.0, 100.0]);

        $report = $this->render(['fine', 'not-run', 'both-lost', 'base-lost', 'head-lost']);

        $this->assertStringStartsWith('## ❌ Issues: unchanged · Performance: regressed', $report);
        // ❌ first, then 💥, then ✅; selection order inside a group.
        $this->assertSame(
            ['❌ head-lost', '💥 not-run', '💥 both-lost', '💥 base-lost', '✅ fine'],
            \array_map(static fn(string $row): string => \explode(' | ', $row)[0], \array_slice($this->tableRows($report), 0, 5)),
        );
    }

    #[Test]
    public function a_lost_base_alone_warns_without_failing(): void
    {
        $this->side('base-lost', 'base', crash: true);
        $this->side('base-lost', 'head', issues: true, perf: [10.0, 80.0, 100.0]);

        $this->assertStringStartsWith('## ⚠️ Issues: not compared · Performance: not compared', $this->render(['base-lost']));
    }

    /**
     * @param array{float, float, float} $base wall, coverage, memory
     * @param array{float, float, float} $head
     */
    #[Test]
    #[DataProvider('badges')]
    public function badge_colors_follow_the_thresholds(array $base, array $head, ?string $wall, ?string $memory, ?string $coverage): void
    {
        $this->side('app', 'base', issues: true, perf: $base);
        $this->side('app', 'head', issues: true, perf: $head);

        $cells = \explode(' | ', $this->tableRows($this->render(['app']))[0]);

        foreach ([1 => $wall, 2 => $memory, 5 => $coverage] as $column => $color) {
            if ($color === null) {
                $this->assertStringNotContainsString('\color', $cells[$column]);
            } else {
                $this->assertStringContainsString('\color{' . $color . '}', $cells[$column]);
            }
        }
    }

    /** @return iterable<string, array{array{float, float, float}, array{float, float, float}, ?string, ?string, ?string}> */
    public static function badges(): iterable
    {
        // Both wall floors must be cleared: 3 s AND 10 %.
        yield 'wall exactly on both floors' => [[30.0, 80.0, 100.0], [33.0, 80.0, 100.0], 'red', null, null];
        yield 'wall 2.9 s is jitter' => [[10.0, 80.0, 100.0], [12.9, 80.0, 100.0], 'gray', null, null];
        yield 'wall 5 s but 5 % is jitter' => [[100.0, 80.0, 100.0], [105.0, 80.0, 100.0], 'gray', null, null];
        yield 'wall faster' => [[30.0, 80.0, 100.0], [20.0, 80.0, 100.0], 'green', null, null];
        yield 'memory exactly +5 % is gray' => [[10.0, 80.0, 100.0], [10.0, 80.0, 105.0], null, 'gray', null];
        yield 'memory +6 % is red' => [[10.0, 80.0, 100.0], [10.0, 80.0, 106.0], null, 'red', null];
        yield 'memory -6 % is green' => [[10.0, 80.0, 100.0], [10.0, 80.0, 94.0], null, 'green', null];
        yield 'coverage +0.004 is jitter' => [[10.0, 80.0, 100.0], [10.0, 80.004, 100.0], null, null, null];
        yield 'coverage +0.01 is green' => [[10.0, 80.0, 100.0], [10.0, 80.01, 100.0], null, null, 'green'];
        yield 'coverage -0.01 is red' => [[10.0, 80.0, 100.0], [10.0, 79.99, 100.0], null, null, 'red'];
    }

    #[Test]
    public function a_base_perf_json_without_peak_rss_renders_a_dash(): void
    {
        $this->side('app', 'base', issues: true, perf: [10.0, 80.0, null]);
        $this->side('app', 'head', issues: true, perf: [10.0, 80.0, 100.0]);

        $cells = \explode(' | ', $this->tableRows($this->render(['app']))[0]);

        $this->assertSame('100', $cells[2]);
    }

    #[Test]
    public function the_total_row_sums_only_apps_compared_on_both_sides(): void
    {
        $this->side('a', 'base', issues: true, perf: [10.0, 80.0, 100.0]);
        $this->side('a', 'head', issues: true, perf: [11.0, 80.0, 100.0]);
        $this->side('b', 'base', issues: true, perf: [20.0, 80.0, 200.0]);
        $this->side('b', 'head', issues: true, perf: [21.0, 80.0, 200.0]);
        // Head crashed: its base figures must not enter the sums.
        $this->side('c', 'base', issues: true, perf: [500.0, 80.0, 900.0]);
        $this->side('c', 'head', crash: true);

        $rows = $this->tableRows($this->render(['a', 'b', 'c']));
        $cells = \explode(' | ', (string) \end($rows));

        $this->assertStringStartsWith('**Total**', $cells[0]);
        $this->assertStringStartsWith('32.0', $cells[1]);
        $this->assertStringStartsWith('300', $cells[2]);
    }

    /**
     * @param array{float, float, float|null}|null $perf wall, coverage, peak RSS
     */
    private function side(string $app, string $side, bool $issues = false, ?array $perf = null, bool $crash = false): void
    {
        $label = $side === 'base' ? 'base-aaaa' : 'pr-bbbb';
        @\mkdir($this->dir . '/' . $app);
        $prefix = "{$this->dir}/{$app}/{$app}-{$label}--";

        if ($issues) {
            \file_put_contents($prefix . 'issues.json', '[]');
        }

        if ($perf !== null) {
            \file_put_contents($prefix . 'perf.json', \json_encode([
                'wall_seconds' => $perf[0],
                'type_coverage_pct' => $perf[1],
                'peak_rss_mb' => $perf[2],
            ], \JSON_THROW_ON_ERROR));
        }

        if ($crash) {
            \file_put_contents($prefix . 'crash.log', "=== x ===\n--- stderr ---\nboom\n--- stdout ---\n");
        }
    }

    /** @param list<string> $apps */
    private function render(array $apps): string
    {
        $process = \proc_open(
            [
                \PHP_BINARY,
                \dirname(__DIR__, 3) . '/bin/ci/delta-report.php',
                $this->dir,
                'base-aaaa',
                'pr-bbbb',
                '--apps=' . \implode(',', $apps),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $this->assertSame(0, \proc_close($process), $stderr);

        return $stdout;
    }

    /**
     * App rows and the Total row, as "| a | b |" lines with the outer pipes cut off.
     *
     * @return list<string>
     */
    private function tableRows(string $report): array
    {
        $rows = [];
        foreach (\explode("\n", $report) as $line) {
            if (\str_starts_with($line, '| ') && !\str_starts_with($line, '| App ')) {
                $rows[] = \substr($line, 2, -2);
            }
        }

        return $rows;
    }
}
