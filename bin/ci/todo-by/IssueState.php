<?php

declare(strict_types=1);

namespace PsalmLaravelPlugin\Ci\TodoBy;

/** What the GitHub API says about an issue or pull request: `state` is `open` or `closed`. */
final readonly class IssueState
{
    public function __construct(
        public string $state,
        public ?string $closedAt = null,
    ) {}

    public function isClosed(): bool
    {
        return $this->state === 'closed';
    }
}
