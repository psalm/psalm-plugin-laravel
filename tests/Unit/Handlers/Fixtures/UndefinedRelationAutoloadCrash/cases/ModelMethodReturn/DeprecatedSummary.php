<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ModelMethodReturn;

// Model method return type: registry warm-up checks it for Attribute and Relation.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedSummary {}
