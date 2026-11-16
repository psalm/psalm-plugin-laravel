<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** @extends Builder<User> */
final class UserBuilder extends Builder
{
    public function fluentSelf(): self
    {
        return $this->where('active', true);
    }

    public function fluentStatic(): static
    {
        return $this->where('active', true);
    }

    /** @return $this */
    public function fluentThis()
    {
        return $this->where('active', true);
    }

    /** @return self|Collection<int, User> */
    public function builderOrCollection(bool $load): self|Collection
    {
        return $load ? $this->get() : $this->where('active', true);
    }
}
