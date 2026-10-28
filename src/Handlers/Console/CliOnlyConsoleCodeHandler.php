<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Seeder;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Marks artisan commands, migrations and seeders `@psalm-cli-only`, so what they echo/print is not reported
 * as TaintedHtml: it goes to a terminal, not an HTML response. Other sinks (SQL, shell, secrets) still apply.
 *
 * Psalm reads the flag only from the class whose method is being analyzed, never from its ancestors, so a
 * stub on the base classes would not reach userland subclasses; hence setting it on every subclass's storage.
 * Methods inherited from traits are analyzed under the trait and stay uncovered.
 *
 * Known gap: a command run through `Artisan::call()` inside an HTTP request writes raw `echo` output into the
 * response. Commands print through `$this->info()` and friends (not an echo sink) in practice.
 */
final class CliOnlyConsoleCodeHandler implements AfterCodebasePopulatedInterface
{
    private const CLI_ONLY_BASES = [
        Command::class,
        Migration::class,
        Seeder::class,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $bases = \array_map(\strtolower(...), self::CLI_ONLY_BASES);

        foreach ($event->getCodebase()->classlike_storage_provider::getAll() as $storage) {
            foreach ($bases as $base) {
                if (isset($storage->parent_classes[$base])) {
                    $storage->cli_only = true;
                    break;
                }
            }
        }
    }
}
