<?php

declare(strict_types=1);

namespace PsalmLaravelPlugin\Ci\TodoBy;

/**
 * One `@todo-by` occurrence. A marker whose line does not follow the grammar carries `$problem` and no target.
 */
final readonly class TodoByMarker
{
    private function __construct(
        public string $file,
        public int $line,
        public ?string $url,
        public ?string $owner,
        public ?string $repo,
        public ?int $number,
        public string $description,
        public ?string $problem,
    ) {}

    public static function valid(string $file, int $line, string $url, string $owner, string $repo, int $number, string $description): self
    {
        return new self($file, $line, $url, $owner, $repo, $number, $description, null);
    }

    public static function malformed(string $file, int $line, string $problem, string $text): self
    {
        return new self($file, $line, null, null, null, null, $text, $problem);
    }

    public function isValid(): bool
    {
        return $this->problem === null;
    }

    /** Issues and pull requests share one number space per repository, so `issues/5` and `pull/5` are the same key. */
    public function key(): ?string
    {
        return $this->isValid() ? \strtolower("{$this->owner}/{$this->repo}#{$this->number}") : null;
    }
}
