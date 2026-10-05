<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Probe;

// Analyzed with the fixture project (see psalm.xml); the test fails on any issue in this file.
// Only the plugin's config handler types `config('app.name')` (Laravel's helper declares mixed), so
// this reports MixedReturnStatement whenever the plugin disabled itself at init: an init-time failure
// would otherwise pass as "no crash". Not a root alias such as `\Str`: a booted app registers those at
// runtime, and Psalm then reflects them without the plugin.
function plugin_active(): string
{
    return config('app.name');
}
