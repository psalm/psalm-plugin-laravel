<?php

declare(strict_types=1);

namespace BladeTaintRemapFixture;

// #1519: an ordinary application sink a template hands tainted input to. The taint reaches it
// through resources/views/external.blade.php, so only the journey should name the template.
final class Sink
{
    public static function raw(string $value): void
    {
        echo $value;
    }
}
