<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\ForeignIdFor;

// Named by a migration's foreignIdFor(): SchemaAggregator checks whether it is a Model while building the
// schema at plugin init, before Psalm populates its storage.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedCustomer {}
