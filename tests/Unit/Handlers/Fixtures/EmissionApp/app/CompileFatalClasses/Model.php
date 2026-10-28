<?php

declare(strict_types=1);

namespace CompileFatalFixture;

use Illuminate\Database\Eloquent\Model;

/** Same compile-time fatal as Cache.php, on the model registration path. */
class Model extends Model {}
