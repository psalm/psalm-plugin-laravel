<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for the vendor directives whose compiled code reports issues on lines the template
 * author never wrote (#1908). Each body is the vendor's own compile output: Livewire's `@entangle`
 * (SupportEntangle), Inertia's `@inertia`/`@inertiaHead` (Directive, its root id fixed to `app`),
 * and Filament's `@capture`/`@endcapture` (SupportServiceProvider, the argument-less form).
 */
final class CompiledResidualStubProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('entangle', static fn(string $expression): string => <<<EOT
            <?php if ((object) ({$expression}) instanceof \Livewire\WireDirective) : ?>window.Livewire.find('{{ \$__livewire->getId() }}').entangle('{{ {$expression}->value() }}'){{ {$expression}->hasModifier('live') ? '.live' : '' }}<?php else : ?>window.Livewire.find('{{ \$__livewire->getId() }}').entangle('{{ {$expression} }}')<?php endif; ?>
            EOT);

        Blade::directive('inertia', static fn(): string => self::oneLine('<?php
            if (!isset($__inertiaSsrDispatched)) {
                $__inertiaSsrDispatched = true;
                $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page);
            }

            if ($__inertiaSsrResponse) {
                echo $__inertiaSsrResponse->body;
            } elseif (config(\'inertia.use_script_element_for_initial_page\')) {
                ?><script data-page="app" type="application/json">{!! json_encode($page) !!}</script><div id="app"></div><?php
            } else {
                ?><div id="app" data-page="{{ json_encode($page) }}"></div><?php
            }
        ?>'));

        Blade::directive('inertiaHead', static fn(): string => self::oneLine('<?php
            if (!isset($__inertiaSsrDispatched)) {
                $__inertiaSsrDispatched = true;
                $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page);
            }

            if ($__inertiaSsrResponse) {
                echo $__inertiaSsrResponse->head;
            }
        ?>'));

        Blade::directive('capture', static fn(string $expression): string => "
                <?php {$expression} = (function (\$args) {
                    return function () use (\$args) {
                        extract(\$args, EXTR_SKIP);
                        ob_start(); ?>
            ");

        Blade::directive('endcapture', static fn(): string => "
                <?php return new \\Illuminate\\Support\\HtmlString(ob_get_clean()); };
                    })(get_defined_vars()); ?>
            ");
    }

    /** `Inertia\Directive` joins its template onto one line before returning it. */
    private static function oneLine(string $template): string
    {
        return \implode(' ', \array_map('trim', \explode("\n", $template)));
    }
}
