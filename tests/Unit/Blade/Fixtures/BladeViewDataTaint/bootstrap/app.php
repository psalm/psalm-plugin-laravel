<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A package-style namespace hint outside vendor/, registered the way a local package's provider does.
$app->register(new class ($app) extends ServiceProvider {
    public function boot(): void
    {
        $this->loadViewsFrom(\dirname(__DIR__) . '/resources/pkg-views', 'pkg');

        // BookStack's theme `@include` override: the scope goes to a renderer that is not `$__env`.
        $this->app->make('blade.compiler')->directive(
            'themedInclude',
            static fn(string $expression): string => "<?php echo \$__themeViews->handleViewInclude({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])); ?>",
        );
    }
});

return $app;
