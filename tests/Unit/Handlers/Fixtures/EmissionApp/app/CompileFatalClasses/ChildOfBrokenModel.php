<?php

declare(strict_types=1);

namespace CompileFatalFixture;

/** Compiles fine itself, but autoloading it loads the unloadable Model parent first. */
class ChildOfBrokenModel extends Model {}
