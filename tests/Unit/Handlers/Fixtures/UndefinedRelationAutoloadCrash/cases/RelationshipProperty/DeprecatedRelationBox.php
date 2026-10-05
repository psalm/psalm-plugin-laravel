<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\RelationshipProperty;

// Union member of a relation method return type, read through a magic property.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedRelationBox {}
