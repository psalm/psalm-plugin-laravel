<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CustomCollection;

// Second paginator value next to a model: CustomCollectionHandler counts the Model members of the union.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedCustomCollectionValue {}
