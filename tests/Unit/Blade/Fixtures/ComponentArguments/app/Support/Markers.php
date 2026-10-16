<?php

declare(strict_types=1);

namespace App\Support;

final class Markers
{
    /** @psalm-pure */
    public static function label(): Marker&\Stringable
    {
        return new class implements Marker, \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'ok';
            }
        };
    }
}
