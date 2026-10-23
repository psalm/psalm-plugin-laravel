<?php

declare(strict_types=1);

namespace App\Contracts;

interface HasTitle
{
    /** @psalm-impure */
    public function title(): string;
}
