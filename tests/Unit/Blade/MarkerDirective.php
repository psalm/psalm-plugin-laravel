<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

/**
 * An invokable directive handler whose compiled output depends on constructor state that
 * `ReflectionFunction::getStaticVariables()` never sees (#1517 M1): two instances bound to two
 * different `$mark` values are indistinguishable by file + static variables alone, because the
 * state lives on `$this`, not in a `use()` clause.
 */
final class MarkerDirective
{
    public function __construct(private readonly string $mark) {}

    public function __invoke(): string
    {
        return '<?php echo "' . $this->mark . '"; ?>';
    }
}
