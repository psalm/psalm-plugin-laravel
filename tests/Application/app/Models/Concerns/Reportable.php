<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Contract a polymorphic relation can require next to Model: `@return MorphTo<Model&Reportable, self>`.
 */
interface Reportable {}
