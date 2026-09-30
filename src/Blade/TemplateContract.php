<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * What a Blade template declares and reads: `@var` types, `@props` entries,
 * the top-level variables its compiled body actually reads, and the
 * `@psalm-suppress` comments attached to a source line. Unwired until the
 * `BladeBootstrapper` follow-up consumes it to feed `ShadowCompiler::compile()`.
 *
 * @psalm-immutable
 * @psalm-api
 */
final class TemplateContract
{
    /**
     * @param array<string, ContractVar> $vars          variable name (without $) => declaration
     * @param list<string>               $readVariables variable names (without $) the compiled body reads
     * @param array<int, list<string>>   $suppressions  template line => suppressed rules
     */
    public function __construct(
        public readonly array $vars,
        public readonly array $readVariables,
        public readonly array $suppressions,
        public readonly bool $propsUnknown,
    ) {}

    /**
     * @return array<string, string> variable name (without $) => FQCN, the channel
     *                                {@see ShadowCompiler::compile()} and {@see PreludeBuilder::build()} expect
     */
    public function contractVars(): array
    {
        $types = [];

        foreach ($this->vars as $name => $var) {
            $types[$name] = $var->typeString;
        }

        return $types;
    }
}
