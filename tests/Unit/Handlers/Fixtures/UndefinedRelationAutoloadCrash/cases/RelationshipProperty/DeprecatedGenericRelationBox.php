<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\RelationshipProperty;

// Generic union member of a relation method return type, read through a magic property.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/**
 * @template TKey of array-key
 * @template TValue
 */
final class DeprecatedGenericRelationBox {}
