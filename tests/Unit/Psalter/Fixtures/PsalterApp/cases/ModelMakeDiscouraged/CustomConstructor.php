<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Draft;

final class CustomConstructor
{
    public function build(): Draft
    {
        return Draft::make();
    }
}
