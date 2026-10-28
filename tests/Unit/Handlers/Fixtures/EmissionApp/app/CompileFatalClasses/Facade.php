<?php

declare(strict_types=1);

namespace CompileFatalFixture;

use Illuminate\Support\Facades\Facade;

/** Direct Facade parent: reaches the scan-time (afterClassLikeVisit) guard, then the populate-time one. */
class Facade extends Facade {}
