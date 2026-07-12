<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Drives resolveBaseModel() -> concreteModel(), the pre-fix autoload site.
DeprecatedOnLoad::with('posts');

// Drives resolveModelFromType() -> modelFromAtomic() -> isClassOrSubclassOf(), the other pre-fix
// autoload site.
function drive_instance_path(DeprecatedOnLoadInstance $x): void
{
    $x->with('posts');
}
