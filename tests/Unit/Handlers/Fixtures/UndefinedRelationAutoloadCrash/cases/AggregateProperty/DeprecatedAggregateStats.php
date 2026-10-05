<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateProperty;

// Return type of the method a `{relation}_count` property names: ModelAggregatePropertyHandler checks it for Relation.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedAggregateStats {}
