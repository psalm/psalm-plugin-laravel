<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\Contracts\Support\Htmlable;

/** An `Htmlable` that does NOT declare `__toString()`: echoing one raw is a runtime fatal. */
final class NoToStringHtmlable implements Htmlable
{
    #[\Override]
    public function toHtml(): string
    {
        return '<b>';
    }
}
