<?php

declare(strict_types=1);

namespace BladeBaselineFixture;

// Memberless and final with no __get, so the misspelled property in the template is the only issue.
/** @psalm-immutable */
final class Greeter {}
