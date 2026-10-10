<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A nullable producer for the template-assigned flag cases (#1808): a property fetch on the
// possibly-null result is the issue a lost flag correlation reports.
final class MaybeItem
{
    public string $name = '';

    public static function find(): ?self
    {
        return \random_int(0, 1) === 1 ? new self() : null;
    }

    public static function flip(): bool
    {
        return \random_int(0, 1) === 1;
    }

    public function ready(): bool
    {
        return $this->name !== '';
    }
}
