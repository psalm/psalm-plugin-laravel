<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * Output of `InitCommand::resolveCompanionPlugins`: what to splice into
 * psalm.xml, what to print after a successful write, and what hints to emit
 * for missing companion plugins.
 *
 * A flat list of fields is clearer than a positional tuple at the call site
 * and lets the "nothing detected" case use a named constructor instead of
 * the sentinel `['', [], []]`.
 *
 * @psalm-immutable
 */
final readonly class CompanionPluginDecisions
{
    /**
     * @param list<string> $confirmations one-liners describing plugins that
     *                                    were auto-enabled (Case A)
     * @param list<string> $hints         multi-line blocks suggesting plugins
     *                                    the user hasn't installed (Case B)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $xmlFragment,
        public array $confirmations,
        public array $hints,
    ) {}

    /** @psalm-pure */
    public static function none(): self
    {
        return new self('', [], []);
    }
}
