<?php

declare(strict_types=1);

namespace App\Support;

/** Same shape as a component (a static `resolve()` taking data, a public `$message`), but not a `Component`. */
final class Lookalike
{
    public string $message = '';

    /** @param array<string, mixed> $data */
    public static function resolve(array $data): self
    {
        $self = new self();
        $self->message = '';

        return $self;
    }

    public function show(): void
    {
        echo $this->message;
    }
}
