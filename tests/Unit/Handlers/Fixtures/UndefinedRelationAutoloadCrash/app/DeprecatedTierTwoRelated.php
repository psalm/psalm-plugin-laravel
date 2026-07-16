<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Tier-2 target: referenced only inside TierTwoModel::deprecatedRel()'s `@return` generic, so Psalm
// scans it but never loads its file — until RelationResolver's pre-fix `is_a($related, Model::class,
// true)` autoloads it while walking the `deprecatedRel.child` dot-path. Not a Model subclass, for the
// same reason as DeprecatedOnLoad: ModelRegistrationHandler autoloads every Model on its own during
// warm-up, which would crash here first and defeat the isolation of the resolver site under test.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

class DeprecatedTierTwoRelated
{
}
