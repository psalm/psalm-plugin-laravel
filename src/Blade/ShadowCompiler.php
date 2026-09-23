<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Compiles a Blade template into an analyzable PHP "shadow" file, with a
 * line map back to the original template and `{{-- @psalm-suppress --}}`
 * comments carried across. Contract extraction (what `$contractVars` should
 * be for a given template) is the caller's responsibility.
 */
final class ShadowCompiler
{
    private readonly PreludeBuilder $preludeBuilder;

    private readonly SuppressionInjector $suppressionInjector;

    public function __construct(private readonly BladeCompiler $compiler)
    {
        $this->preludeBuilder = new PreludeBuilder();
        $this->suppressionInjector = new SuppressionInjector();
    }

    /**
     * @param array<string, string> $contractVars variable name (without $) => FQCN
     */
    public function compile(string $templatePath, string $source, array $contractVars = []): ShadowResult|BladeCompileError
    {
        // The prefix is absent from author text, including real PHP comments.
        $markerPrefix = 'blade:' . \hash('xxh128', $source) . ':';
        while (\str_contains($source, $markerPrefix)) {
            $markerPrefix .= ':';
        }

        $marked = MarkerPrePass::inject($source, $markerPrefix);

        try {
            $compiled = $this->compiler->compileString($marked);
        } catch (\Throwable $throwable) {
            return BladeCompileError::fromThrowable($templatePath, $throwable);
        }

        if (PreludeBuilder::isComponentView($source)) {
            $compiled = AttributesRestoreReassert::apply($compiled);
        }

        $prelude = $this->preludeBuilder->build($compiled, $contractVars, $source);
        $content = $prelude . $compiled;

        $preludeLines = \substr_count($prelude, "\n");
        $lineMap = LineMapBuilder::build($content, $preludeLines, $markerPrefix);

        $suppressions = $this->suppressionInjector->resolve($content, $source, $lineMap, $markerPrefix);
        $content = $this->suppressionInjector->inject($content, $source, $lineMap, $markerPrefix);

        return new ShadowResult($content, $lineMap, MarkerPrePass::extendsLine($source), $suppressions);
    }
}
