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
     * @param array<string, string> $propTypes variable name (without $) => the type a `@props` literal
     *                                         default gives the body, see {@see ContractVar::$defaultType}
     */
    public function compile(string $templatePath, string $source, array $propTypes = []): ShadowResult|BladeCompileError
    {
        // The prefix is absent from author text, including real PHP comments.
        $markerPrefix = MarkerComment::prefixFor($source);

        $marked = MarkerPrePass::inject($source, $markerPrefix);

        // Psalm's global error handler would turn a PHP error raised by a directive (a prose
        // `@component` passes null to str_contains()) into an exception naming the vendor file and
        // the whole Psalm command line. Raised here instead, it names the directive.
        \set_error_handler($this->throwCompileError(...));

        try {
            $compiled = $this->compiler->compileString($marked);
        } catch (\Throwable $throwable) {
            return BladeCompileError::fromThrowable($templatePath, $throwable);
        } finally {
            \restore_error_handler();
        }

        if (PreludeBuilder::isComponentView($source) && !AttributesRestoreReassert::templateAssignsAttributes($source)) {
            $compiled = AttributesRestoreReassert::apply($compiled);
        }

        $prelude = $this->preludeBuilder->build($compiled, $source, $propTypes);
        $content = $prelude . $compiled;

        $preludeLines = \substr_count($prelude, "\n");
        $lineMap = LineMapBuilder::build($content, $preludeLines, $markerPrefix);

        $suppressions = $this->suppressionInjector->resolve($content, $source, $lineMap, $markerPrefix);
        $content = $this->suppressionInjector->inject($content, $source, $lineMap, $markerPrefix);

        return new ShadowResult($content, $lineMap, MarkerPrePass::extendsLine($source), $suppressions);
    }

    /**
     * @throws \ErrorException for every error `error_reporting()` covers, `@`-silenced ones excluded
     */
    private function throwCompileError(int $severity, string $message, string $file, int $line): bool
    {
        if ((\error_reporting() & $severity) === 0) {
            return false;
        }

        foreach (\debug_backtrace() as $frame) {
            // compileStatement() receives Blade's match: [1] the directive name, [3] its
            // parenthesized arguments, null when the directive has none.
            if ($frame['function'] !== 'compileStatement' || ($frame['class'] ?? null) !== BladeCompiler::class) {
                continue;
            }

            $match = $frame['args'][0] ?? null;

            if (!\is_array($match) || !isset($match[1]) || !\is_string($match[1])) {
                break;
            }

            $directive = $match[1];

            // PHP's deprecation for a null passed to a string parameter: the directive needed the
            // arguments it was not given.
            if (($match[3] ?? null) === null && \str_contains($message, 'Passing null to parameter')) {
                $message = "@{$directive} needs arguments; write @@{$directive} to print it as text";
            } else {
                $message = "@{$directive}: {$message}";
            }

            break;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}
