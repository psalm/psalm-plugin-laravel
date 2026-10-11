<?php

declare(strict_types=1);

namespace Fx;

final class User
{
    public string $name = '';

    public function label(): string
    {
        return $this->name;
    }
}
