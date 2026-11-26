<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

/** An attribute unrelated to scopes whose class must never be loaded by the analysis. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AuditedAttribute {}

// Loading this file raises a deprecation, like a package attribute with an implicitly nullable parameter
// on PHP 8.4+. Psalm's error handler turns it into an uncaught exception.
\trigger_error('AuditedAttribute was loaded', \E_USER_DEPRECATED);
