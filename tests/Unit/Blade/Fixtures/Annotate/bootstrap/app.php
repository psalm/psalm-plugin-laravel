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
        // Written as a string: the plugin reads the composer's method from the analysed source, so the
        // class need not be loadable here.
        $this->app->make('view')->composer('edge-composed', 'AnnotateFixture\\EdgeComposer');

        // #2216: BookStack's theme `@include` override renders through a shared object, not `$__env`;
        // `quietRender` names a view without handing over the scope.
        $blade = $this->app->make('blade.compiler');
        $blade->directive(
            'themedInclude',
            static fn(string $expression): string => "<?php echo \$__themeViews->handleViewInclude({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])); ?>",
        );
        // The view name sits after the scope: no Laravel signature describes it.
        $blade->directive(
            'themedLast',
            static fn(string $expression): string => "<?php echo \$__themeViews->render(array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]), {$expression}); ?>",
        );
        $blade->directive(
            'quietRender',
            static fn(string $expression): string => "<?php echo \$__themeViews->renderQuietly({$expression}); ?>",
        );
    }
});

return $app;
