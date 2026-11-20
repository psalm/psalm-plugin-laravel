<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Marks a value read from the environment Psalm runs in as chosen by the person running it.
 *
 * Like Psalm's own environment variables, the plugin's name the files and directories it reads and writes (its
 * cache, the control file of `psalm-laravel annotate`, the Laravel application to boot): the plugin is meant to act on
 * them, they aren't input from a third party. The other kinds of taint stay, so that a value still has to be escaped
 * for a shell, for example.
 *
 * @internal
 * @psalm-immutable
 */
final class OperatorEnvironment
{
    /**
     * @template T
     * @param T $value
     * @return T
     * @psalm-pure
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape include
     * @psalm-taint-escape callable
     * @psalm-taint-escape html
     * @psalm-taint-escape has_quotes
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     */
    public static function chosen(mixed $value): mixed
    {
        return $value;
    }
}
