<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The three ways a Blade template can emit a PHP expression to output.
 *
 * - ESCAPED   — `{{ $var }}` compiles to `echo e($var)`; htmlspecialchars applied.
 * - RAW       — `{!! $var !!}` compiles to `echo $var`; no escaping.
 * - PHP_BLOCK — code inside `@php ... @endphp`; anything printed there bypasses
 *               Blade's auto-escape.
 *
 * Only ESCAPED is safe by default. RAW and PHP_BLOCK are taint sinks for `html`.
 *
 * @psalm-api
 */
enum BladeEchoKind
{
    case Escaped;
    case Raw;
    case PhpBlock;

    /**
     * True for any kind that does NOT pass through Blade's htmlspecialchars
     * wrapper, i.e. {!! !!} and @php ... @endphp.
     *
     * @psalm-mutation-free
     */
    public function isUnescaped(): bool
    {
        return $this !== self::Escaped;
    }
}
