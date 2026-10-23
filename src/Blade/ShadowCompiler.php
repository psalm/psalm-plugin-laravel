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
            // A typed compile method (`compileJs(string)`) rejects the null on entry, before any PHP error.
            $trace = $throwable instanceof \TypeError ? $throwable->getTrace() : [];
            $directive = $this->directiveOf($trace);
            $advice = $directive !== null && ($trace[0]['function'] ?? null) === 'compile' . \ucfirst($directive[0])
                ? $this->argumentAdvice($directive, $throwable->getMessage())
                : null;

            return $advice !== null
                ? new BladeCompileError($templatePath, $advice)
                : BladeCompileError::fromThrowable($templatePath, $throwable);
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

        $directive = $this->directiveOf(\debug_backtrace());

        if ($directive !== null) {
            $message = $this->argumentAdvice($directive, $message) ?? "@{$directive[0]}: {$message}";
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * The directive being compiled, read off compileStatement()'s argument: Blade's match, [1] the
     * directive name, [3] its parenthesized arguments, null when the directive has none.
     *
     * @param list<array{args?: array<array-key, mixed>, class?: string, file?: string, function?: string, line?: int, object?: object, type?: string}> $trace
     *
     * @return array{string, bool}|null the directive name, and whether it was written without arguments
     *
     * @psalm-pure
     */
    private function directiveOf(array $trace): ?array
    {
        foreach ($trace as $frame) {
            if (($frame['function'] ?? null) !== 'compileStatement' || ($frame['class'] ?? null) !== BladeCompiler::class) {
                continue;
            }

            $args = $frame['args'] ?? [];

            if (!isset($args[0]) || !\is_array($args[0])) {
                return null;
            }

            $match = $args[0];

            return isset($match[1]) && \is_string($match[1]) ? [$match[1], !isset($match[3])] : null;
        }

        return null;
    }

    /**
     * Advice for a built-in directive written without the argument its compile method requires, when
     * the error is that null reaching it. A custom directive never gets null (callCustomDirective()
     * passes ''), and a method without a required parameter (`compileParent()`) fails for another reason.
     *
     * @param array{string, bool} $directive
     */
    private function argumentAdvice(array $directive, string $message): ?string
    {
        [$name, $bare] = $directive;

        if (!$bare || \preg_match('/Passing null to parameter|null given/', $message) !== 1
            || isset($this->compiler->getCustomDirectives()[$name])
            || !\method_exists($this->compiler, $method = 'compile' . \ucfirst($name))) {
            return null;
        }

        $parameter = (new \ReflectionMethod($this->compiler, $method))->getParameters()[0] ?? null;

        return $parameter !== null && !$parameter->isOptional()
            ? "@{$name} needs arguments; write @@{$name} to print it as text"
            : null;
    }
}
