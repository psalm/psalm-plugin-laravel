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

    /**
     * @psalm-mutation-free
     */
    public function __construct(private readonly BladeCompiler $compiler)
    {
        $this->preludeBuilder = new PreludeBuilder();
        $this->suppressionInjector = new SuppressionInjector();
    }

    /**
     * @param array<string, string> $contractVars variable name (without $) => FQCN
     * @param array{class: string, keys: list<string>, scope: string}|null $component
     */
    public function compile(string $templatePath, string $source, array $contractVars = [], ?array $component = null): ShadowResult|BladeCompileError
    {
        // The prefix is absent from author text, including real PHP comments.
        $markerPrefix = MarkerComment::prefixFor($source);

        $marked = MarkerPrePass::inject($source, $markerPrefix);

        try {
            $compiled = $this->compiler->compileString($marked);
        } catch (\Throwable $throwable) {
            return BladeCompileError::fromThrowable($templatePath, $throwable);
        }

        if (PreludeBuilder::isComponentView($source) && !AttributesRestoreReassert::templateAssignsAttributes($source)) {
            $compiled = AttributesRestoreReassert::apply($compiled);
        }

        $prelude = $this->preludeBuilder->build($compiled, $contractVars, $source, $component);
        $content = $prelude . $compiled;

        $preludeLines = \substr_count($prelude, "\n");
        $lineMap = LineMapBuilder::build($content, $preludeLines, $markerPrefix);

        // The scope statement right after `<?php` is render()'s own code, not the template's.
        $scopeLines = $component === null ? 0 : \substr_count($component['scope'], "\n");

        for ($line = 2; $line <= $scopeLines + 1; ++$line) {
            $lineMap[$line] = ShadowEntry::RENDER_DATA_LINE;
        }

        $suppressions = $this->suppressionInjector->resolve($content, $source, $lineMap, $markerPrefix);
        $content = $this->suppressionInjector->inject($content, $source, $lineMap, $markerPrefix);

        return new ShadowResult($content, $lineMap, MarkerPrePass::extendsLine($source), $suppressions);
    }
}
