<?php

declare(strict_types=1);

namespace PsalmLaravelPlugin\Ci\TodoBy;

/**
 * Outcome of one run. Exit codes: 1 = something to act on (a malformed marker or a closed upstream issue),
 * 2 = the API could not say and nothing else needs action, 0 = clean. A closed issue outranks an API failure
 * because it is actionable, and the failure is still printed.
 */
final readonly class TodoByReport
{
    /**
     * @param int $markerCount valid and malformed markers seen
     * @param int $resolvedCount distinct upstream issues the resolver was asked about
     * @param list<TodoByMarker> $malformed
     * @param list<array{TodoByMarker, IssueState}> $closed
     * @param list<array{TodoByMarker, string}> $unavailable markers whose upstream state could not be fetched, with the reason
     */
    public function __construct(
        public int $markerCount,
        public int $resolvedCount,
        public array $malformed,
        public array $closed,
        public array $unavailable,
    ) {}

    public function exitCode(): int
    {
        if ($this->malformed !== [] || $this->closed !== []) {
            return 1;
        }

        return $this->unavailable !== [] ? 2 : 0;
    }

    /** @return list<string> one `file:line <url> <state> <description>` line per finding */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->malformed as $marker) {
            $lines[] = "{$marker->file}:{$marker->line} - invalid {$marker->problem} {$marker->description}";
        }

        foreach ($this->closed as [$marker, $state]) {
            $lines[] = "{$marker->file}:{$marker->line} {$marker->url} closed " . ($state->closedAt ?? 'unknown-date') . " {$marker->description}";
        }

        foreach ($this->unavailable as [$marker, $reason]) {
            $lines[] = "{$marker->file}:{$marker->line} {$marker->url} unavailable ({$reason}) {$marker->description}";
        }

        return $lines;
    }

    /** @return list<string> GitHub Actions `::error` workflow commands, same findings as lines() */
    public function annotations(): array
    {
        $annotations = [];
        foreach ($this->malformed as $marker) {
            $annotations[] = self::annotation($marker, "Invalid @todo-by {$marker->problem}");
        }

        foreach ($this->closed as [$marker, $state]) {
            $annotations[] = self::annotation($marker, "Upstream {$marker->url} is closed (" . ($state->closedAt ?? 'unknown date') . "): {$marker->description}");
        }

        foreach ($this->unavailable as [$marker, $reason]) {
            $annotations[] = self::annotation($marker, "Could not read upstream state of {$marker->url}: {$reason}");
        }

        return $annotations;
    }

    private static function annotation(TodoByMarker $marker, string $message): string
    {
        $property = static fn(string $value): string => \strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
        $data = \strtr($message, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);

        return "::error file={$property($marker->file)},line={$marker->line}::{$data}";
    }
}
