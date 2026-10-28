<?php

declare(strict_types=1);

namespace CompileFatalFixture;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/** Loads fine; only the analysis stub (stubs/compile-fatal.phpstub) declares it with a clashing import. */
class StubbedModel extends EloquentModel {}
