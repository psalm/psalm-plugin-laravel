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
    public function compile(string $templatePath, string $source, array $propTypes = []): ShadowResult|CompileFailureReason
    {
        // The prefix is absent from author text, including real PHP comments.
        $markerPrefix = MarkerComment::prefixFor($source);

        $marked = MarkerPrePass::inject($source, $markerPrefix);

        // Psalm's global error handler would turn a PHP error raised by a directive (a prose
        // `@component` passes null to str_contains()) into an exception naming the vendor file and
        // the whole Psalm command line. Raised here instead (same class and code), it names the directive.
        \set_error_handler($this->throwCompileError(...));

        try {
            $compiled = $this->compiler->compileString($marked);
        } catch (\Throwable $throwable) {
            $advice = $throwable instanceof \TypeError ? $this->typedArgumentAdvice($throwable) : null;

            return $advice !== null
                ? new CompileFailureReason($templatePath, $advice)
                : CompileFailureReason::fromThrowable($templatePath, $throwable);
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

        return new ShadowResult($content, $lineMap, $suppressions);
    }

    /**
     * @throws \RuntimeException for every error `error_reporting()` covers, `@`-silenced ones excluded:
     *                           the class and code Psalm's own handler throws, so a directive or
     *                           precompiler catching it compiles as it would under Psalm
     */
    private function throwCompileError(int $severity, string $message): bool
    {
        if ((\error_reporting() & $severity) === 0) {
            return false;
        }

        $directive = $this->directiveOf(\debug_backtrace());

        if ($directive !== null) {
            $message = $this->argumentAdvice($directive, $message) ?? "@{$directive[0]}: {$message}";
        }

        throw new \RuntimeException($message, $severity);
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

        if (!$bare || !\str_contains($message, 'Passing null to parameter')
            || isset($this->compiler->getCustomDirectives()[$name])
            || !\method_exists($this->compiler, $method = 'compile' . \ucfirst($name))) {
            return null;
        }

        // Only Laravel's own methods: a subclass override may fail on a null of its own. A trait
        // method reports the using class, so every built-in directive qualifies.
        $reflection = new \ReflectionMethod($this->compiler, $method);
        $parameter = $reflection->getParameters()[0] ?? null;

        return $reflection->getDeclaringClass()->getName() === BladeCompiler::class
            && $parameter !== null && !$parameter->isOptional()
            ? $this->needsArguments($name)
            : null;
    }

    /**
     * A typed compile method (`compileJs(string)`) rejects the null on entry, before any PHP error.
     * Read off the frame names alone, which survive `zend.exception_ignore_args`: compileStatement()
     * passes a null first argument only for a directive written without arguments.
     *
     * @psalm-capabilities read-props
     */
    private function typedArgumentAdvice(\TypeError $error): ?string
    {
        $trace = $error->getTrace();
        $method = $trace[0]['function'] ?? '';

        if (($trace[0]['class'] ?? null) !== BladeCompiler::class
            || ($trace[1]['function'] ?? null) !== 'compileStatement'
            || !\str_starts_with($method, 'compile')
            || \preg_match('/^[^ ]+::' . \preg_quote($method, '/') . '\(\): Argument #1 \(\$\w+\) must be of type [^,]+, null given/', $error->getMessage()) !== 1) {
            return null;
        }

        return $this->needsArguments(\lcfirst(\substr($method, 7)));
    }

    /** @psalm-pure */
    private function needsArguments(string $directive): string
    {
        return "@{$directive} needs arguments; write @@{$directive} to print it as text";
    }
}
