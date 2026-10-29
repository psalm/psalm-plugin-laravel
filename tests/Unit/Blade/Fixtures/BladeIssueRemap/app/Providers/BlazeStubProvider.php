<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Blaze\BlazeManager;

/**
 * Stands in for livewire/blaze: an earliest string preparation callback that, while enabled,
 * rewrites a component tag into the `require` and `ltrim(ob_get_clean())` Blaze emits around it,
 * and while disabled but debugging, still instruments it (`compileForDebug()`). Debug is on through
 * config and cached at boot, as Blaze's debugger middleware registration does.
 */
final class BlazeStubProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(BlazeManager::class);
        config(['blaze.debug' => true]);
    }

    public function boot(): void
    {
        $blaze = $this->app->make(BlazeManager::class);

        $this->app->booted(function () use ($blaze): void {
            $blaze->isDebugging();

            $this->app->make(BladeCompiler::class)->prepareStringsForCompilationUsing(
                static function (string $input) use ($blaze): string {
                    if (!\str_contains($input, '<x-blaze-probe />')) {
                        return $input;
                    }

                    if (!$blaze->isEnabled()) {
                        return $blaze->isDebugging()
                            ? \str_replace('<x-blaze-probe />', '<?php $__blaze->debugger->start(); ?><x-blaze-probe />', $input)
                            : $input;
                    }

                    return \str_replace(
                        '<x-blaze-probe />',
                        "<?php require \$__blaze->compiledPath.'/probe.php'; echo ltrim(ob_get_clean()); ?>",
                        $input,
                    );
                },
            );
        });
    }
}
