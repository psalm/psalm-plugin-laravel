<?php

declare(strict_types=1);

namespace WithPivotFixture;

/** A `Date::use()` class that is no Carbon: its factory methods may return anything, so the dates are not provably it. */
final class PlainDate extends \DateTimeImmutable {}
