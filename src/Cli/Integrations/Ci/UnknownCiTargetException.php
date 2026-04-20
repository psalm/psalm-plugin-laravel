<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Integrations\Ci;

use InvalidArgumentException;

/**
 * Raised by CiTargetRegistry when an unknown target name is requested.
 *
 * Subclassing InvalidArgumentException keeps it idiomatic (the user-supplied
 * argument is wrong) while giving AddCommand a dedicated type to catch without
 * swallowing unrelated InvalidArgumentExceptions from console parsing. The
 * $name and $supportedIds properties are retained (rather than only the
 * formatted message) so downstream handlers can format friendlier errors or
 * suggest a close-match target without re-parsing the message.
 */
final class UnknownCiTargetException extends \InvalidArgumentException
{
    /**
     * @param string       $name        the rejected target name
     * @param list<string> $supportedIds canonical ids the registry does know about
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $name,
        public readonly array $supportedIds,
    ) {
        parent::__construct(\sprintf(
            'Unknown target "%s". Supported: %s, ci.',
            $this->name,
            \implode(', ', $this->supportedIds),
        ));
    }
}
