<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// Deliberately incomplete app, owned solely by
// PluginMissingRouteInitializationTest::a_throwing_routes_are_cached_check_degrades_only_this_feature.
//
// There is no bootstrap/cache directory next to this file and no withRouting() call. Without the
// cache directory BootProviders never completes, so the 'files' binding stays unregistered, and
// routesAreCached() throws a BindingResolutionException when it resolves it. That throw is the
// regression under test. Adding a bootstrap/cache directory or any provider that binds 'files'
// silently turns that test into a no-op, which is why the test asserts !$app->bound('files')
// before exercising the branch.
//
// Kept separate from the sibling complete-boot fixture (../../bootstrap/app.php), which needs the
// opposite shape: a full boot with a populated named-route table.
return Application::configure(basePath: \dirname(__DIR__))->create();
