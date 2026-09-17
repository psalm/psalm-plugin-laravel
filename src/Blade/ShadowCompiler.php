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
        $marked = MarkerPrePass::inject($source);

        try {
            $compiled = $this->compiler->compileString($marked);
        } catch (\Throwable $throwable) {
            return BladeCompileError::fromThrowable($templatePath, $throwable);
        }

        $prelude = $this->preludeBuilder->build($compiled, $contractVars);
        $content = $prelude . $compiled;

        $preludeLines = \substr_count($prelude, "\n");
        $lineMap = LineMapBuilder::build($content, $preludeLines);

        $content = $this->suppressionInjector->inject($content, $source, $lineMap);

        return new ShadowResult($content, $lineMap, MarkerPrePass::extendsLine($source));
    }
}
