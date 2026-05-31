<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeTemplateScanner;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;
use Psalm\LaravelPlugin\Blade\PsalmBladeCompiler;

/**
 * Integration tests that exercise the real {@see PsalmBladeCompiler} against a
 * full {@see BladeTemplateScanner} pipeline. Distinct from
 * {@see BladeTemplateScannerTest}: this file is the place to add fixtures that
 * cover compiled-Blade quirks the regex backend could not reach, and to verify
 * the scanner does not crash on real-world template shapes.
 *
 * No Testbench / Laravel container needed: {@see PsalmBladeCompiler} builds a
 * compiler with a default {@see \Illuminate\Filesystem\Filesystem} and a temp
 * cache path, so the scanner has no boot dependency.
 */
final class BladeTemplateScannerCompilerIntegrationTest extends TestCase
{
    private BladeTemplateScanner $scanner;

    #[\Override]
    protected function setUp(): void
    {
        $this->scanner = new BladeTemplateScanner(new PsalmBladeCompiler());
    }

    public function test_php_endphp_immediately_followed_by_echo_does_not_leak_placeholder(): void
    {
        /*
         * Regression for an upstream Laravel quirk: the raw-block placeholder
         * `@__raw_block_N__@` collides with `compileEchos`' `(@)?{{...}}`
         * escape-brace capture when `@endphp` is followed by `{{`. The
         * PsalmBladeCompiler preprocesses the source to insert whitespace
         * between `@endphp` / `@endverbatim` and an adjacent `{` (or `@`)
         * so the placeholder survives restoration AND the echo is compiled.
         *
         * The analysis check verifies the classification; the scan() check
         * verifies the echo was actually compiled to a real Stmt\Echo_ node
         * (an uncompiled `{{ $x }}` would leave `$x` invisible to scan()).
         */
        $source = "@php \$x = 1; @endphp{{ \$x }}";

        $analysis = $this->scanner->analyze($source);
        $usages = $this->scanner->scan($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
        $this->assertNotSame(
            [],
            \array_filter($usages, static fn(\Psalm\LaravelPlugin\Blade\BladeVariableUsage $u): bool => $u->name === 'x'),
            'Echo of $x after @endphp must reach the AST walker; an empty result means the placeholder leaked and the echo was rendered literally.',
        );
    }

    public function test_php_endphp_immediately_followed_by_raw_echo_does_not_leak_placeholder(): void
    {
        $source = "@php \$x = 1; @endphp{!! \$x !!}";

        $analysis = $this->scanner->analyze($source);
        $usages = $this->scanner->scan($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
        $this->assertNotSame(
            [],
            \array_filter($usages, static fn(\Psalm\LaravelPlugin\Blade\BladeVariableUsage $u): bool => $u->name === 'x'),
            'Raw echo of $x after @endphp must compile to a real echo statement.',
        );
    }

    public function test_two_adjacent_php_blocks_compile_cleanly(): void
    {
        $source = "@php \$x = 1; @endphp@php \$y = 2; @endphp";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->unsafeKeys);
    }

    public function test_verbatim_immediately_followed_by_echo_is_isolated(): void
    {
        $source = "@verbatim{{vue}}@endverbatim{{ \$x }}";

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(['x'], \array_unique(
            \array_map(static fn(\Psalm\LaravelPlugin\Blade\BladeVariableUsage $u): string => $u->name, $this->scanner->scan($source)),
        ));
        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
    }

    public function test_unresolved_component_tag_does_not_throw(): void
    {
        /*
         * In production the user's component class may not be loaded in the
         * compiler's container. The compiler's component-tag pass would
         * normally throw an InvalidArgumentException / BindingResolutionException
         * when it tries to resolve the class. {@see PsalmBladeCompiler}
         * overrides {@see \Illuminate\View\Compilers\BladeCompiler::compileComponentTags()}
         * to capture the post-raw-block source and leave the markup
         * unresolved, so the scanner can record a {@see BladeComponentEdge}
         * (PR-6b) or fall back to UNKNOWN(ComponentTag) for an unresolvable
         * shape, instead of crashing.
         *
         * The self-closing form with one bound attribute is resolvable at
         * the scanner layer; {@see BladeSafetyMap::build()} is responsible
         * for picking a candidate view name from the scanned roots.
         */
        $source = '<x-some-unregistered-component :data="$data" />';

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertContains(BladeUncertaintyReason::ComponentResolved, $analysis->uncertainties);
        $this->assertCount(1, $analysis->componentEdges);
    }

    public function test_realistic_email_template_classifies_safe(): void
    {
        $source = <<<'BLADE'
            <!DOCTYPE html>
            <html>
            <body>
                <h1>Hello, {{ $user->name }}!</h1>
                <p>Your balance is {{ number_format($balance, 2) }}.</p>
                @foreach ($transactions as $tx)
                    <li>{{ $tx->description }}: {{ $tx->amount }}</li>
                @endforeach
                <footer>{{ config('app.name') }}</footer>
            </body>
            </html>
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Safe, $analysis->kind);
        $this->assertSame([], $analysis->uncertainties);
    }

    public function test_realistic_admin_template_with_raw_html_surfaces_unsafe_key(): void
    {
        $source = <<<'BLADE'
            <article>
                <h1>{{ $post->title }}</h1>
                <div class="body">{!! $post->renderedHtml !!}</div>
                <p>By {{ $post->author }}</p>
            </article>
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $analysis->kind);
        $this->assertSame(['post'], $analysis->unsafeKeys);
    }

    public function test_real_world_layout_template_classifies_unknown(): void
    {
        // Realistic shape from a notification email layout: section + push +
        // include + component all in one template.
        $source = <<<'BLADE'
            @extends('mail::layouts.main')

            @section('header')
                @include('partials.logo')
            @endsection

            @push('styles')
                <style>body { color: black; }</style>
            @endpush

            @section('body')
                <p>{!! $bodyHtml !!}</p>
            @endsection
            BLADE;

        $analysis = $this->scanner->analyze($source);

        $this->assertSame(BladeViewSafetyKind::Unknown, $analysis->kind);
        $this->assertNotEmpty($analysis->uncertainties);
        $this->assertContains('bodyHtml', $analysis->unsafeKeys);
    }
}
