<?php

declare(strict_types=1);

namespace CompileFatalFixture;

use Illuminate\Support\Facades\Cache;

/**
 * PHP refuses to compile this file: the class name clashes with the `use` import above. A compile-time
 * fatal cannot be caught, so autoloading it kills the whole process (#2136).
 */
class Cache extends Cache {}
