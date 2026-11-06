<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A package-style namespace hint, registered the way a local package's provider does, and a macro
// on the Livewire stand-in (the fixture autoloader maps only `App\`).
$app->register(new class ($app) extends ServiceProvider {
    public function boot(): void
    {
        $this->loadViewsFrom(\dirname(__DIR__) . '/resources/pkg-views', 'pkg');

        require_once \dirname(__DIR__) . '/app/LivewireStandIn.php';
        \Livewire\Component::macro('flash', static fn(string $message): string => $message);

        // BookStack's theme `@include` override renders through a shared object, not `$__env`; the
        // second directive names a view without handing over the scope.
        $blade = $this->app->make('blade.compiler');
        $blade->directive(
            'themedInclude',
            static fn(string $expression): string => "<?php echo \$__themeViews->handleViewInclude({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])); ?>",
        );
        $blade->directive(
            'quietRender',
            static fn(string $expression): string => "<?php echo \$__themeViews->renderQuietly({$expression}); ?>",
        );
    }
});

return $app;
