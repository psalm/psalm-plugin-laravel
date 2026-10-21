<?php

declare(strict_types=1);

namespace PsalmLaravelPlugin\Ci\TodoBy;

/**
 * Parses `@todo-by <GitHub issue or PR URL> <what to remove>` markers and decides which ones an upstream
 * resolution has made actionable. Pure logic: the file list and the network come from the caller, so the
 * tests need neither.
 */
final class TodoByChecker
{
    private const FORMAT = 'expected `@todo-by https://github.com/<owner>/<repo>/(issues|pull)/<n> <what to remove>`';

    /**
     * A marker is the tag right behind a comment opener (`//`, `#`, or the `*` of a docblock line or `/*`).
     * Prose that merely mentions the tag, in backticks or a sentence, is not a marker.
     */
    private const TAG = '~(?://|#|\*)[ \t]*@todo-by(?![\w-])(.*)$~';

    private const TARGET = '~^(https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/(?:issues|pull)/(\d+))(?=[ \t]|$)[ \t]*(.*)$~';

    /** Top-level directories scanned when the file list comes from `git ls-files`. */
    private const SCANNED_ROOTS = ['src', 'stubs', 'tests', 'docs', 'bin', '.github'];

    /** The checker's own sources and fixtures spell malformed markers on purpose. */
    private const SELF_PATHS = [
        'bin/ci/check-todo-by.php',
        'bin/ci/todo-by/',
        'tests/Unit/Ci/TodoByCheckerTest.php',
        '.github/workflows/todo-by.yml',
    ];

    /** @return list<TodoByMarker> */
    public static function parse(string $file, string $contents): array
    {
        $markers = [];
        foreach (\preg_split('/\r\n|\n|\r/', $contents) ?: [] as $index => $text) {
            if (\preg_match(self::TAG, $text, $tag) !== 1) {
                continue;
            }

            $line = $index + 1;
            // A one-line docblock or HTML/PHP comment closes on the same line; the closer is not the description.
            $rest = \trim(\preg_replace('~\s*(?:\*/|-->|\?>)\s*$~', '', \trim($tag[1])) ?? $tag[1]);

            if (\preg_match(self::TARGET, $rest, $target) !== 1) {
                $markers[] = TodoByMarker::malformed($file, $line, 'marker: ' . self::FORMAT . '.', $rest);
                continue;
            }

            if ($target[5] === '') {
                $markers[] = TodoByMarker::malformed($file, $line, "marker: {$target[1]} has no description; say what to remove.", '');
                continue;
            }

            $markers[] = TodoByMarker::valid($file, $line, $target[1], $target[2], $target[3], (int) $target[4], $target[5]);
        }

        return $markers;
    }

    /** Whether `git ls-files` output path `$path` (repo-relative, `/` separated) is a scan target. */
    public static function isScannable(string $path): bool
    {
        $segments = \explode('/', $path);
        if (!\in_array($segments[0], self::SCANNED_ROOTS, true) || \in_array('vendor', $segments, true)) {
            return false;
        }

        foreach (self::SELF_PATHS as $self) {
            if ($path === $self || (\str_ends_with($self, '/') && \str_starts_with($path, $self))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<TodoByMarker> $markers
     * @param (\Closure(TodoByMarker): IssueState)|null $resolve null = format check only (no network); a
     *        throwing resolver marks that upstream issue unavailable, never closed. Called once per distinct issue.
     */
    public static function check(array $markers, ?\Closure $resolve): TodoByReport
    {
        $malformed = \array_values(\array_filter($markers, static fn(TodoByMarker $m): bool => !$m->isValid()));
        $closed = [];
        $unavailable = [];
        /** @var array<string, IssueState|string> $outcomes state, or the failure reason */
        $outcomes = [];

        if ($resolve !== null) {
            foreach ($markers as $marker) {
                $key = $marker->key();
                if ($key === null) {
                    continue;
                }

                if (!isset($outcomes[$key])) {
                    try {
                        $outcomes[$key] = $resolve($marker);
                    } catch (\Throwable $e) {
                        $outcomes[$key] = $e->getMessage();
                    }
                }

                $outcome = $outcomes[$key];
                if (\is_string($outcome)) {
                    $unavailable[] = [$marker, $outcome];
                } elseif ($outcome->isClosed()) {
                    $closed[] = [$marker, $outcome];
                }
            }
        }

        return new TodoByReport(\count($markers), \count($outcomes), $malformed, $closed, $unavailable);
    }
}
