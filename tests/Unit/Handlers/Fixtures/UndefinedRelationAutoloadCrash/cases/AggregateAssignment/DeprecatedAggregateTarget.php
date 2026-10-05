<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateAssignment;

// Assigned from a static call: ModelAggregateLoadHandler::singleModel() checks whether it is a Model.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedAggregateTarget {}
