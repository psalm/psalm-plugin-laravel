<?php

declare(strict_types=1);

namespace Fx;

/**
 * A project class that `resources/views/class-collision-project.blade.php` redeclares under the same
 * name with a correct body. This file's own findings must not depend on whether Blade analysis is on.
 */
final class CollidedReport
{
    public function total(): int
    {
        return 'not an int';
    }
}
