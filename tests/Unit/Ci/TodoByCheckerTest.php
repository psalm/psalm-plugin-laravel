<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PsalmLaravelPlugin\Ci\TodoBy\IssueState;
use PsalmLaravelPlugin\Ci\TodoBy\TodoByChecker;
use PsalmLaravelPlugin\Ci\TodoBy\TodoByMarker;

require_once \dirname(__DIR__, 3) . '/bin/ci/todo-by/bootstrap.php';

/**
 * Guards the parsing and decision logic of `bin/ci/check-todo-by.php`. The upstream lookup is a fake closure, so
 * nothing here touches the network.
 */
final class TodoByCheckerTest extends TestCase
{
    private const ISSUE = 'https://github.com/vimeo/psalm/issues/12322';

    #[Test]
    #[DataProvider('commentStyles')]
    public function a_marker_is_recognised_behind_every_comment_opener(string $source): void
    {
        $markers = TodoByChecker::parse('f.php', $source);

        $this->assertCount(1, $markers);
        $this->assertTrue($markers[0]->isValid());
        $this->assertSame(self::ISSUE, $markers[0]->url);
        $this->assertSame('vimeo', $markers[0]->owner);
        $this->assertSame('psalm', $markers[0]->repo);
        $this->assertSame(12322, $markers[0]->number);
        $this->assertSame('remove this handler', $markers[0]->description);
    }

    /** @return iterable<string, array{string}> */
    public static function commentStyles(): iterable
    {
        yield 'slashes' => ['// @todo-by ' . self::ISSUE . ' remove this handler'];
        yield 'docblock line' => ["/**\n * @todo-by " . self::ISSUE . " remove this handler\n */"];
        yield 'one-line docblock' => ['/** @todo-by ' . self::ISSUE . ' remove this handler */'];
        yield 'hash' => ['# @todo-by ' . self::ISSUE . ' remove this handler'];
        yield 'trailing comment' => ['$x = 1; // @todo-by ' . self::ISSUE . ' remove this handler'];
        yield 'tight opener' => ['//@todo-by ' . self::ISSUE . ' remove this handler'];
        yield 'CRLF' => ["<?php\r\n// @todo-by " . self::ISSUE . " remove this handler\r\n"];
    }

    #[Test]
    public function a_pull_request_url_is_a_valid_target(): void
    {
        $marker = TodoByChecker::parse('f.php', '// @todo-by https://github.com/vimeo/psalm/pull/12322 remove this handler')[0];

        $this->assertTrue($marker->isValid());
        $this->assertSame(12322, $marker->number);
    }

    #[Test]
    #[DataProvider('malformed')]
    public function a_marker_without_a_usable_target_is_a_format_error(string $source): void
    {
        $markers = TodoByChecker::parse('f.php', $source);

        $this->assertCount(1, $markers);
        $this->assertFalse($markers[0]->isValid());
        $this->assertNull($markers[0]->key());
        $this->assertNotNull($markers[0]->problem);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'no URL' => ['// @todo-by remove this handler'];
        yield 'bare tag' => ['// @todo-by'];
        yield 'placeholder' => ['// @todo-by <issue URL> remove this handler'];
        yield 'not GitHub' => ['// @todo-by https://gitlab.com/vimeo/psalm/issues/12322 remove it'];
        yield 'http scheme' => ['// @todo-by http://github.com/vimeo/psalm/issues/12322 remove it'];
        yield 'repo page, not an issue' => ['// @todo-by https://github.com/vimeo/psalm remove it'];
        yield 'discussion' => ['// @todo-by https://github.com/vimeo/psalm/discussions/12322 remove it'];
        yield 'non-numeric' => ['// @todo-by https://github.com/vimeo/psalm/issues/abc remove it'];
        yield 'comment anchor glued to the number' => ['// @todo-by https://github.com/vimeo/psalm/issues/12322#issuecomment-1 remove it'];
        yield 'no description' => ['// @todo-by ' . self::ISSUE];
        yield 'no description in docblock' => ['/** @todo-by ' . self::ISSUE . ' */'];
    }

    #[Test]
    public function prose_that_only_mentions_the_tag_is_not_a_marker(): void
    {
        $source = <<<'TXT'
            Use `@todo-by <url> <what>` markers.
            A @todo-by line names the issue.
            // @todo-bystander is another tag
            TXT;

        $this->assertSame([], TodoByChecker::parse('f.md', $source));
    }

    #[Test]
    public function every_marker_of_a_file_is_found_with_its_line_number(): void
    {
        $source = "<?php\n"
            . '// @todo-by ' . self::ISSUE . " first\n"
            . "\n"
            . "/**\n"
            . " * @todo-by nonsense\n"
            . ' * @todo-by https://github.com/laravel/framework/pull/7 second' . "\n"
            . " */\n";

        $markers = TodoByChecker::parse('f.php', $source);

        $this->assertSame([2, 5, 6], \array_map(static fn(TodoByMarker $m): int => $m->line, $markers));
        $this->assertSame([true, false, true], \array_map(static fn(TodoByMarker $m): bool => $m->isValid(), $markers));
        $this->assertSame('second', $markers[2]->description);
    }

    #[Test]
    public function issue_and_pull_urls_of_one_number_share_a_key_and_owner_case_is_ignored(): void
    {
        $issue = TodoByChecker::parse('a.php', '// @todo-by https://github.com/Vimeo/Psalm/issues/5 a')[0];
        $pull = TodoByChecker::parse('b.php', '// @todo-by https://github.com/vimeo/psalm/pull/5 b')[0];

        $this->assertSame($issue->key(), $pull->key());
    }

    #[Test]
    public function format_checking_never_calls_the_resolver(): void
    {
        $report = TodoByChecker::check(TodoByChecker::parse('f.php', '// @todo-by ' . self::ISSUE . ' x'), null);

        $this->assertSame(0, $report->exitCode());
        $this->assertSame(1, $report->markerCount);
        $this->assertSame(0, $report->resolvedCount);
    }

    #[Test]
    public function a_format_error_fails_even_without_the_network(): void
    {
        $report = TodoByChecker::check(TodoByChecker::parse('f.php', '// @todo-by oops'), null);

        $this->assertSame(1, $report->exitCode());
        $this->assertCount(1, $report->lines());
        $this->assertStringStartsWith('f.php:1 - invalid marker:', $report->lines()[0]);
    }

    #[Test]
    public function an_open_upstream_issue_is_clean(): void
    {
        $report = TodoByChecker::check(
            TodoByChecker::parse('f.php', '// @todo-by ' . self::ISSUE . ' x'),
            static fn(): IssueState => new IssueState('open'),
        );

        $this->assertSame(0, $report->exitCode());
        $this->assertSame([], $report->lines());
    }

    #[Test]
    public function a_closed_upstream_issue_is_reported_with_location_state_and_description(): void
    {
        $report = TodoByChecker::check(
            TodoByChecker::parse('src/Plugin.php', "<?php\n// @todo-by " . self::ISSUE . ' remove this handler'),
            static fn(): IssueState => new IssueState('closed', '2026-10-20T21:38:40Z'),
        );

        $this->assertSame(1, $report->exitCode());
        $this->assertSame(['src/Plugin.php:2 ' . self::ISSUE . ' closed 2026-10-20T21:38:40Z remove this handler'], $report->lines());
        $this->assertSame(
            ['::error file=src/Plugin.php,line=2::Upstream ' . self::ISSUE . ' is closed (2026-10-20T21:38:40Z): remove this handler'],
            $report->annotations(),
        );
    }

    #[Test]
    public function a_url_shared_by_several_markers_is_resolved_once_and_every_marker_is_reported(): void
    {
        $calls = [];
        $markers = [
            ...TodoByChecker::parse('a.php', '// @todo-by ' . self::ISSUE . ' one'),
            ...TodoByChecker::parse('b.php', "\n// @todo-by https://github.com/vimeo/psalm/pull/12322 two"),
            ...TodoByChecker::parse('c.php', '// @todo-by https://github.com/vimeo/psalm/issues/1 three'),
        ];

        $report = TodoByChecker::check($markers, static function (TodoByMarker $marker) use (&$calls): IssueState {
            $calls[] = $marker->key();

            return new IssueState($marker->number === 1 ? 'open' : 'closed', '2026-01-01T00:00:00Z');
        });

        $this->assertSame(['vimeo/psalm#12322', 'vimeo/psalm#1'], $calls);
        $this->assertSame(2, $report->resolvedCount);
        $this->assertSame(3, $report->markerCount);
        $this->assertCount(2, $report->closed);
        $this->assertSame(['a.php:1', 'b.php:2'], \array_map(static fn(array $c): string => "{$c[0]->file}:{$c[0]->line}", $report->closed));
    }

    #[Test]
    public function an_api_failure_is_never_reported_as_resolved(): void
    {
        $report = TodoByChecker::check(
            TodoByChecker::parse('f.php', '// @todo-by ' . self::ISSUE . ' x'),
            static fn(): never => throw new \RuntimeException('HTTP 403: rate limit exceeded'),
        );

        $this->assertSame(2, $report->exitCode());
        $this->assertSame([], $report->closed);
        $this->assertSame(['f.php:1 ' . self::ISSUE . ' unavailable (HTTP 403: rate limit exceeded) x'], $report->lines());
    }

    #[Test]
    public function a_closed_issue_outranks_an_api_failure_in_the_exit_code_and_both_are_printed(): void
    {
        $markers = [
            ...TodoByChecker::parse('a.php', '// @todo-by https://github.com/o/r/issues/1 closed one'),
            ...TodoByChecker::parse('b.php', '// @todo-by https://github.com/o/r/issues/2 unreachable one'),
        ];

        $report = TodoByChecker::check($markers, static fn(TodoByMarker $marker): IssueState => $marker->number === 1
            ? new IssueState('closed')
            : throw new \RuntimeException('HTTP 502'));

        $this->assertSame(1, $report->exitCode());
        $this->assertCount(2, $report->lines());
        $this->assertStringContainsString('closed unknown-date closed one', $report->lines()[0]);
        $this->assertStringContainsString('unavailable (HTTP 502)', $report->lines()[1]);
    }

    #[Test]
    public function annotations_escape_workflow_command_metacharacters(): void
    {
        $marker = TodoByChecker::parse('dir/a,b:c.php', '// @todo-by https://github.com/o/r/issues/1 100% done')[0];
        $report = TodoByChecker::check([$marker], static fn(): IssueState => new IssueState('closed', 'T'));

        $this->assertSame(
            ['::error file=dir/a%2Cb%3Ac.php,line=1::Upstream https://github.com/o/r/issues/1 is closed (T): 100%25 done'],
            $report->annotations(),
        );
    }

    #[Test]
    #[DataProvider('paths')]
    public function only_tracked_source_trees_are_scanned(string $path, bool $scanned): void
    {
        $this->assertSame($scanned, TodoByChecker::isScannable($path));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'src' => ['src/Plugin.php', true];
        yield 'stub' => ['stubs/common/Support/helpers.phpstub', true];
        yield 'phpt' => ['tests/Type/tests/Model/FactoryUnboundCountKnownLimitation.phpt', true];
        yield 'docs' => ['docs/contributing/README.md', true];
        yield 'bin' => ['bin/ci/check-phpt-conventions.sh', true];
        yield 'workflow' => ['.github/workflows/tests.yml', true];
        yield 'root file' => ['AGENTS.md', false];
        yield 'vendored fixture' => ['tests/Application/vendor/foo/bar.php', false];
        yield 'the checker itself' => ['bin/ci/todo-by/TodoByChecker.php', false];
        yield 'the checker script' => ['bin/ci/check-todo-by.php', false];
        yield 'the checker test' => ['tests/Unit/Ci/TodoByCheckerTest.php', false];
        yield 'the workflow' => ['.github/workflows/todo-by.yml', false];
        yield 'similar name is scanned' => ['bin/ci/check-todo-by-other.php', true];
    }
}
