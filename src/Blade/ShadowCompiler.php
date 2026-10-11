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
     * @param array<string, ContractVar> $contractVars variable name (without $) => the declaration
     *                                                 whose type the prelude gives the body
     */
    public function compile(string $templatePath, string $source, array $contractVars = []): ShadowResult|BladeCompileError
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

        $prelude = $this->preludeBuilder->build(
            $compiled,
            \array_map(static fn(ContractVar $var): string => $var->typeString, $contractVars),
            $source,
        );
        $content = $prelude . $compiled;

        $preludeLines = \substr_count($prelude, "\n");
        $lineMap = LineMapBuilder::build($content, $preludeLines, $markerPrefix);

        // Psalm reports a contract type's own fault (an unknown class) on its prelude statement;
        // the template comment that declared it is where the author can fix it.
        foreach (SourceLines::split($prelude) as $index => $line) {
            if (\preg_match('/\$(' . ContractParser::IDENTIFIER . ') \*\/;\s*$/', $line, $match) === 1
                && isset($contractVars[$match[1]])
            ) {
                $lineMap[$index + 1] = $contractVars[$match[1]]->declarationLine;
            }
        }

        $suppressions = $this->suppressionInjector->resolve($content, $source, $lineMap, $markerPrefix);
        $content = $this->suppressionInjector->inject($content, $source, $lineMap, $markerPrefix);

        return new ShadowResult($content, $lineMap, MarkerPrePass::extendsLine($source), $suppressions);
    }
}
