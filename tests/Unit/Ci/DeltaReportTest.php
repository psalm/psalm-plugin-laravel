<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards `bin/ci/delta-report.php`, the comparator behind the `/psalm-delta`
 * PR comment. Its failure mode is silent: a mis-bucketed issue or a swallowed
 * crash still renders a plausible report, so a regression would mislead PR
 * authors without failing anything. Each case writes a small artifact tree
 * (the same `<app>/<app>-<label>-cache--*.json` layout the workflow downloads)
 * and asserts on what a reader of the rendered markdown would conclude.
 */
final class DeltaReportTest extends TestCase
{
    private const BASE = 'base-aaaa1111';

    private const HEAD = 'pr-bbbb2222';

    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/psalm-delta-report-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    #[Test]
    public function a_message_change_at_the_same_place_is_changed_not_added_and_removed(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 10, 'MissingReturnType', 'expects Foo')]);
        $this->issues('app', self::HEAD, [$this->issue('a.php', 10, 'MissingReturnType', 'expects Foo&static')]);

        $report = $this->report(['app'], ['--details']);

        // added, removed, changed, moved, net
        $this->assertSame([0, 0, 1, 0, 0], $this->row($report, 'app'));
        $this->assertStringContainsString('expects Foo`', $report, 'old message is shown');
        $this->assertStringContainsString('expects Foo&static`', $report, 'new message is shown');
    }

    #[Test]
    public function a_relocated_issue_is_moved_and_does_not_count_toward_added_or_removed(): void
    {
        $this->issues('app', self::BASE, [$this->issue('old.php', 4, 'MissingPureAnnotation', 'env must be pure')]);
        $this->issues('app', self::HEAD, [$this->issue('new.php', 4, 'MissingPureAnnotation', 'env must be pure')]);

        $this->assertSame([0, 0, 0, 1, 0], $this->row($this->report(['app']), 'app'));
    }

    #[Test]
    public function a_column_shift_on_the_same_line_is_moved_not_added_and_removed(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 7, 'MixedMethodCall', 'on mixed', column: 3)]);
        $this->issues('app', self::HEAD, [$this->issue('a.php', 7, 'MixedMethodCall', 'on mixed', column: 9)]);

        $this->assertSame([0, 0, 0, 1, 0], $this->row($this->report(['app']), 'app'));
    }

    #[Test]
    public function unpaired_entries_stay_added_and_removed_beside_changed_and_moved(): void
    {
        $this->issues('app', self::BASE, [
            $this->issue('a.php', 1, 'TypeA', 'old text'),
            $this->issue('b.php', 2, 'TypeB', 'same text'),
            $this->issue('c.php', 3, 'FixedType', 'gone'),
        ]);
        $this->issues('app', self::HEAD, [
            $this->issue('a.php', 1, 'TypeA', 'new text'),
            $this->issue('z.php', 9, 'TypeB', 'same text'),
            $this->issue('d.php', 4, 'NewType', 'fresh'),
            $this->issue('e.php', 5, 'NewType', 'fresh too'),
        ]);

        // +2 new, -1 fixed, 1 changed, 1 moved; net = head (4) - base (3)
        $this->assertSame([2, 1, 1, 1, 1], $this->row($this->report(['app']), 'app'));
    }

    #[Test]
    public function a_duplicate_issue_gaining_or_losing_a_copy_is_counted(): void
    {
        $dup = $this->issue('a.php', 5, 'MixedOperand', 'operand is mixed');
        $this->issues('grows', self::BASE, [$dup, $dup]);
        $this->issues('grows', self::HEAD, [$dup, $dup, $dup]);
        $this->issues('shrinks', self::BASE, [$dup, $dup, $dup]);
        $this->issues('shrinks', self::HEAD, [$dup]);

        $report = $this->report(['grows', 'shrinks']);

        $this->assertSame([1, 0, 0, 0, 1], $this->row($report, 'grows'));
        $this->assertSame([0, 2, 0, 0, -2], $this->row($report, 'shrinks'));
    }

    #[Test]
    public function issues_differing_only_by_column_are_distinct(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 5, 'MixedMethodCall', 'on mixed', column: 3)]);
        $this->issues('app', self::HEAD, [
            $this->issue('a.php', 5, 'MixedMethodCall', 'on mixed', column: 3),
            $this->issue('a.php', 5, 'MixedMethodCall', 'on mixed', column: 20),
        ]);

        $this->assertSame([1, 0, 0, 0, 1], $this->row($this->report(['app']), 'app'));
    }

    #[Test]
    public function identical_runs_report_no_delta(): void
    {
        $issues = [$this->issue('a.php', 1, 'TypeA', 'x'), $this->issue('a.php', 1, 'TypeA', 'x')];
        $this->issues('app', self::BASE, $issues);
        $this->issues('app', self::HEAD, $issues);

        $report = $this->report(['app']);

        $this->assertNull($this->row($report, 'app'), 'unchanged app must not get a delta row');
        $this->assertStringNotContainsString('### Crashed', $report);
    }

    #[Test]
    public function a_long_message_keeps_its_differing_tail_visible(): void
    {
        $prefix = \str_repeat('Illuminate\\Database\\Eloquent\\Relations\\HasMany<App\\Status, App\\Status> ', 20);
        $this->issues('app', self::BASE, [$this->issue('a.php', 1, 'MissingReturnType', $prefix . 'TAIL_OLD')]);
        $this->issues('app', self::HEAD, [$this->issue('a.php', 1, 'MissingReturnType', $prefix . 'TAIL_NEW')]);

        $report = $this->report(['app'], ['--details']);

        $this->assertStringContainsString('TAIL_OLD', $report);
        $this->assertStringContainsString('TAIL_NEW', $report);
        $this->assertLessThan(5_000, \strlen($report), 'long messages are shortened');
    }

    #[Test]
    public function changed_and_moved_entries_are_capped_per_app_with_a_remainder_line(): void
    {
        $base = [];
        $head = [];
        for ($i = 1; $i <= 25; $i++) {
            $base[] = $this->issue('a.php', $i, 'TypeA', "old{$i}");
            $head[] = $this->issue('a.php', $i, 'TypeA', "new{$i}");
        }

        $this->issues('app', self::BASE, $base);
        $this->issues('app', self::HEAD, $head);

        $report = $this->report(['app'], ['--top=5', '--details']);

        $this->assertSame([0, 0, 25, 0, 0], $this->row($report, 'app'), 'the cap must not change the counts');
        $this->assertSame(5, \substr_count($report, '  - old: '));
        $this->assertStringContainsString('… 20 more', $report);
    }

    #[Test]
    public function a_head_only_crash_is_tagged_head(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 1, 'TypeA', 'x')]);
        $this->crashLog('app', self::HEAD, 'HeadBoom');

        $report = $this->report(['app']);

        $this->assertMatchesRegularExpression('/^- \*\*app\*\* \(head only[^)]*\): `HeadBoom`$/m', $report);
        $this->assertNull($this->row($report, 'app'));
    }

    #[Test]
    public function a_base_only_crash_is_tagged_base(): void
    {
        $this->crashLog('app', self::BASE, 'BaseBoom');
        $this->issues('app', self::HEAD, [$this->issue('a.php', 1, 'TypeA', 'x')]);

        $this->assertMatchesRegularExpression('/^- \*\*app\*\* \(base only[^)]*\): `BaseBoom`$/m', $this->report(['app']));
    }

    #[Test]
    public function a_crash_on_both_sides_is_tagged_both(): void
    {
        $this->crashLog('app', self::BASE, 'SameBoom');
        $this->crashLog('app', self::HEAD, 'SameBoom');

        $this->assertMatchesRegularExpression('/^- \*\*app\*\* \(base and head\): `SameBoom`$/m', $this->report(['app']));
    }

    #[Test]
    public function differing_crashes_on_both_sides_show_each_side_error(): void
    {
        $this->crashLog('app', self::BASE, 'BaseBoom');
        $this->crashLog('app', self::HEAD, 'HeadBoom');

        $report = $this->report(['app']);

        $this->assertMatchesRegularExpression('/^- \*\*app\*\*: base: `BaseBoom`; head: `HeadBoom`$/m', $report);
    }

    #[Test]
    public function a_crash_log_without_a_stderr_body_still_counts_as_a_crash(): void
    {
        // e.g. a composer failure: the runner writes only the header line.
        $this->write('app', self::HEAD, 'crash.log', "=== app/pr-bbbb2222 exit 2 after 3s ===\n");
        $this->issues('app', self::BASE, []);

        $report = $this->report(['app']);

        $this->assertMatchesRegularExpression('/^- \*\*app\*\* \(head only[^)]*\): `=== app\/pr-bbbb2222 exit 2/m', $report);
    }

    #[Test]
    public function an_app_with_no_artifacts_is_not_run_rather_than_crashed(): void
    {
        $report = $this->report(['ghost']);

        $this->assertStringNotContainsString('### Crashed', $report);
        $this->assertStringContainsString('ghost', $report);
    }

    #[Test]
    public function an_oversized_report_is_truncated_below_the_comment_limit_keeping_the_summary_and_crashes(): void
    {
        // Every distinct type adds a breakdown line, so 3000 types overflow the cap.
        $base = [];
        $head = [];
        for ($i = 0; $i < 3000; $i++) {
            $type = \sprintf('SomeRatherLongIssueTypeName%04d', $i);
            $base[] = $this->issue('a.php', $i + 1, $type, 'before');
            $head[] = $this->issue('a.php', $i + 1, $type, 'after');
        }

        $this->issues('big', self::BASE, $base);
        $this->issues('big', self::HEAD, $head);
        $this->crashLog('broken', self::HEAD, 'HeadBoom');

        $report = $this->report(['big', 'broken']);

        // The workflow adds a marker and footer (< 500 chars) around the body.
        $this->assertLessThan(65_536 - 500, \strlen($report));
        $this->assertSame([0, 0, 3000, 0, 0], $this->row($report, 'big'), 'summary stays accurate');
        $this->assertMatchesRegularExpression('/^- \*\*broken\*\* \(head only[^)]*\): `HeadBoom`$/m', $report);
        $this->assertStringContainsString('truncated', $report);
        $this->assertSame(\substr_count($report, '<details'), \substr_count($report, '</details>'), 'details block stays closed');
    }

    #[Test]
    public function a_report_under_the_cap_is_not_truncated(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 1, 'TypeA', 'x')]);
        $this->issues('app', self::HEAD, []);

        $this->assertStringNotContainsString('truncated', $this->report(['app']));
    }

    #[Test]
    public function a_multibyte_report_is_capped_in_bytes_not_characters(): void
    {
        // 3-byte characters: the old character-count cap let ~3x the byte limit through.
        $base = [];
        $head = [];
        for ($i = 0; $i < 3000; $i++) {
            $type = \sprintf('Type%04d', $i);
            $base[] = $this->issue('a.php', $i + 1, $type, \str_repeat('前', 40));
            $head[] = $this->issue('a.php', $i + 1, $type, \str_repeat('後', 40));
        }

        $this->issues('big', self::BASE, $base);
        $this->issues('big', self::HEAD, $head);

        $report = $this->report(['big'], ['--details', '--top=5000']);

        $this->assertGreaterThan(30_000, \mb_strlen($report), 'sanity: the multibyte details were rendered, then cut');
        $this->assertLessThan(65_536 - 500, \strlen($report));
        $this->assertStringContainsString('truncated', $report);
        $this->assertTrue(\mb_check_encoding($report, 'UTF-8'), 'the cut must not split a multibyte sequence');
    }

    #[Test]
    public function the_hard_cut_fallback_never_splits_a_multibyte_character(): void
    {
        // Table rows alone overflow the cap, so the breakdown shedding is not enough.
        $apps = [];
        for ($i = 0; $i < 1500; $i++) {
            $app = \sprintf('app前%04d', $i);
            $apps[] = $app;
            $this->issues($app, self::BASE, [$this->issue('a.php', 1, 'TypeA', 'old')]);
            $this->issues($app, self::HEAD, [$this->issue('a.php', 1, 'TypeA', 'new')]);
        }

        $report = $this->report($apps);

        $this->assertLessThan(65_536 - 500, \strlen($report));
        $this->assertStringContainsString('truncated', $report);
        $this->assertTrue(\mb_check_encoding($report, 'UTF-8'));
    }

    #[Test]
    public function without_details_no_file_path_or_message_text_is_printed(): void
    {
        $this->issues('app', self::BASE, [
            $this->issue('secret/Changed.php', 10, 'TypeA', 'SECRET_OLD_MESSAGE'),
            $this->issue('secret/Moved.php', 4, 'TypeB', 'SECRET_MOVED_MESSAGE'),
            $this->issue('secret/Removed.php', 6, 'TypeC', 'SECRET_REMOVED_MESSAGE'),
        ]);
        $this->issues('app', self::HEAD, [
            $this->issue('secret/Changed.php', 10, 'TypeA', 'SECRET_NEW_MESSAGE'),
            $this->issue('secret/Elsewhere.php', 4, 'TypeB', 'SECRET_MOVED_MESSAGE'),
            $this->issue('secret/Added.php', 8, 'TypeD', 'SECRET_ADDED_MESSAGE'),
        ]);

        $plain = $this->report(['app']);

        $this->assertSame([1, 1, 1, 1, 0], $this->row($plain, 'app'), 'aggregate counts are kept');
        $this->assertMatchesRegularExpression('/^- L2: TypeA: 1 -> 1 \(\+0\/-0, 1 changed\)$/m', $plain, 'per-type breakdown is kept');
        $this->assertStringNotContainsString('secret/', $plain);
        $this->assertStringNotContainsString('.php', $plain);
        $this->assertStringNotContainsString('SECRET_', $plain);
        $this->assertStringNotContainsString('**Message changed**', $plain);
        $this->assertStringNotContainsString('**Moved**', $plain);

        $detailed = $this->report(['app'], ['--details']);

        $this->assertSame([1, 1, 1, 1, 0], $this->row($detailed, 'app'));
        $this->assertStringContainsString('secret/Changed.php:10', $detailed);
        $this->assertStringContainsString('SECRET_OLD_MESSAGE', $detailed);
        $this->assertStringContainsString('SECRET_NEW_MESSAGE', $detailed);
        $this->assertStringContainsString('secret/Moved.php:4', $detailed);
        $this->assertStringContainsString('SECRET_MOVED_MESSAGE', $detailed);
    }

    #[Test]
    public function a_message_change_whose_end_line_also_shifted_is_still_changed(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 10, 'TypeA', 'old text', lineTo: 10)]);
        $this->issues('app', self::HEAD, [$this->issue('a.php', 10, 'TypeA', 'new text', lineTo: 11)]);

        $this->assertSame([0, 0, 1, 0, 0], $this->row($this->report(['app']), 'app'));
    }

    #[Test]
    public function a_degraded_or_disabled_plugin_on_either_side_warns(): void
    {
        $this->issues('degraded', self::BASE, []);
        $this->issues('degraded', self::HEAD, []);
        $this->perf('degraded', self::HEAD, ['plugin_status' => 'degraded']);
        $this->issues('disabled', self::BASE, []);
        $this->issues('disabled', self::HEAD, []);
        $this->perf('disabled', self::BASE, ['plugin_status' => 'disabled']);
        $this->issues('fine', self::BASE, []);
        $this->issues('fine', self::HEAD, []);
        $this->perf('fine', self::BASE, ['plugin_status' => 'ok']);
        $this->perf('fine', self::HEAD, ['plugin_status' => 'ok']);

        $report = $this->report(['degraded', 'disabled', 'fine']);

        $this->assertStringContainsString('### Warnings', $report);
        $this->assertMatchesRegularExpression('/^> \*\*degraded\*\*:.*head/m', $report);
        $this->assertMatchesRegularExpression('/^> \*\*disabled\*\*:.*base/m', $report);
        $this->assertDoesNotMatchRegularExpression('/^> \*\*fine\*\*/m', $report);
    }

    #[Test]
    public function differing_versions_threads_or_diverged_deps_warn_but_equal_inputs_do_not(): void
    {
        $versions = ['php' => '8.3.1', 'vimeo/psalm' => '7.0.0-beta24', 'laravel/framework' => '12.1.0'];
        foreach (['versions', 'threads', 'deps'] as $app) {
            $this->issues($app, self::BASE, []);
            $this->issues($app, self::HEAD, []);
        }

        $this->perf('versions', self::BASE, ['versions' => $versions]);
        $this->perf('versions', self::HEAD, ['versions' => ['vimeo/psalm' => '7.0.0-beta25'] + $versions]);
        $this->perf('threads', self::BASE, ['threads' => 1]);
        $this->perf('threads', self::HEAD, ['threads' => 4]);
        $this->perf('deps', self::BASE, ['versions' => $versions]);
        $this->perf('deps', self::HEAD, ['versions' => $versions, 'deps_diverged' => true]);
        $this->issues('same', self::BASE, []);
        $this->issues('same', self::HEAD, []);
        $this->perf('same', self::BASE, ['versions' => $versions, 'threads' => 1]);
        $this->perf('same', self::HEAD, ['versions' => $versions, 'threads' => 1]);

        $report = $this->report(['versions', 'threads', 'deps', 'same']);

        $this->assertMatchesRegularExpression('/^> \*\*versions\*\*:.*7\.0\.0-beta24.*7\.0\.0-beta25/m', $report);
        $this->assertMatchesRegularExpression('/^> \*\*threads\*\*:.*1.*4/m', $report);
        $this->assertMatchesRegularExpression('/^> \*\*deps\*\*:/m', $report);
        $this->assertDoesNotMatchRegularExpression('/^> \*\*same\*\*/m', $report);
    }

    #[Test]
    public function perf_json_without_the_optional_fields_renders_without_warnings(): void
    {
        $this->issues('app', self::BASE, [$this->issue('a.php', 1, 'TypeA', 'x')]);
        $this->issues('app', self::HEAD, []);
        $this->perf('app', self::BASE, ['wall_seconds' => 10.0, 'type_coverage_pct' => 80.0]);
        $this->perf('app', self::HEAD, ['wall_seconds' => 10.0, 'type_coverage_pct' => 80.0]);

        $report = $this->report(['app']);

        $this->assertStringNotContainsString('### Warnings', $report);
        $this->assertSame([0, 1, 0, 0, -1], $this->row($report, 'app'));
    }

    #[Test]
    public function a_tiny_coverage_move_is_reported_from_raw_values(): void
    {
        $this->issues('app', self::BASE, []);
        $this->issues('app', self::HEAD, []);
        $this->perf('app', self::BASE, ['wall_seconds' => 10.0, 'type_coverage_pct' => 84.9520]);
        $this->perf('app', self::HEAD, ['wall_seconds' => 10.0, 'type_coverage_pct' => 84.9512]);
        $this->issues('flat', self::BASE, []);
        $this->issues('flat', self::HEAD, []);
        $this->perf('flat', self::BASE, ['wall_seconds' => 10.0, 'type_coverage_pct' => 90.1234]);
        $this->perf('flat', self::HEAD, ['wall_seconds' => 10.0, 'type_coverage_pct' => 90.1234]);

        $report = $this->report(['app', 'flat']);

        $this->assertMatchesRegularExpression('/^\| app \| 84\.9520 \| 84\.9512 \| -0\.0008 \|$/m', $report);
        $this->assertStringNotContainsString('| flat | 90.1234', $report, 'unchanged coverage is not listed');
    }

    /**
     * @param list<string> $apps
     * @param list<string> $extraArgs
     */
    private function report(array $apps, array $extraArgs = []): string
    {
        $command = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 3) . '/bin/ci/delta-report.php')
            . ' ' . \escapeshellarg($this->dir)
            . ' ' . self::BASE . ' ' . self::HEAD
            . ' --apps=' . \escapeshellarg(\implode(',', $apps))
            . ' --date-marker=cache'
            . ($extraArgs === [] ? '' : ' ' . \implode(' ', \array_map(\escapeshellarg(...), $extraArgs)))
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, \implode("\n", $output));

        return \implode("\n", $output);
    }

    /**
     * Parse an app's row of the "Per-app delta — Issues" table.
     *
     * @return list<int>|null added, removed, changed, moved, net — null when the app has no row
     */
    private function row(string $report, string $app): ?array
    {
        $pattern = '/^\| ' . \preg_quote($app, '/') . ' \| (\d+) \| (\d+) \| (\d+) \| (\d+) \| ([+-]\d+) \|$/m';
        if (\preg_match($pattern, $report, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
    }

    /** @return array<string, mixed> */
    private function issue(string $file, int $line, string $type, string $message, int $column = 1, ?int $lineTo = null): array
    {
        return [
            'file_path' => $file,
            'line_from' => $line,
            'line_to' => $lineTo ?? $line,
            'column_from' => $column,
            'column_to' => $column + 5,
            'type' => $type,
            'message' => $message,
            'error_level' => 2,
        ];
    }

    /** @param list<array<string, mixed>> $issues */
    private function issues(string $app, string $label, array $issues): void
    {
        $this->write($app, $label, 'issues.json', \json_encode($issues, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $perf */
    private function perf(string $app, string $label, array $perf): void
    {
        $this->write($app, $label, 'perf.json', \json_encode($perf, \JSON_THROW_ON_ERROR));
    }

    private function crashLog(string $app, string $label, string $error): void
    {
        $this->write($app, $label, 'crash.log', "=== {$app}/{$label} exit 1 after 2s ===\n--- stderr ---\n{$error}\n--- stdout ---\n");
    }

    private function write(string $app, string $label, string $suffix, string $contents): void
    {
        $appDir = $this->dir . '/' . $app;
        if (!\is_dir($appDir)) {
            \mkdir($appDir, 0o777, true);
        }

        \file_put_contents("{$appDir}/{$app}-{$label}-cache--{$suffix}", $contents);
    }

    private function removeTree(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }

            \rmdir($path);
        } elseif (\is_file($path)) {
            \unlink($path);
        }
    }
}
