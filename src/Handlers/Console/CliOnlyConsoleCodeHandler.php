<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Application;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Marks artisan commands, migrations and seeders `@psalm-cli-only`, so what they echo/print is not reported
 * as TaintedHtml: it goes to a terminal, not an HTML response. Other sinks (SQL, shell, secrets) still apply.
 *
 * Psalm reads the flag only from the class whose method is being analyzed, never from its ancestors, so a
 * stub on the base classes would not reach userland subclasses; hence setting it on every subclass's storage.
 * Methods inherited from traits are analyzed under the trait and stay uncovered.
 *
 * Psalm never treats a file's top-level code as CLI-only on its own, so two kinds of files are marked too:
 * - a migration file, recognized by its top-level `return new class extends Migration`, whatever directory it
 *   lives in (`loadMigrationsFrom()`, `--path`, module layouts). The migrator is its only loader, and without
 *   the mark the instantiation of the CLI-only anonymous class reports CliOnlyCall;
 * - `routes/console.php`, which the console kernel alone loads. A file with another name passed to
 *   `withRouting(commands: ...)` is not recognized.
 * The functions and classes such a file declares are not covered (Psalm's rule for marked files).
 *
 * Known gap: a command run through `Artisan::call()` inside an HTTP request writes raw `echo` output into the
 * response. Commands print through `$this->info()` and friends (not an echo sink) in practice.
 */
final class CliOnlyConsoleCodeHandler implements AfterCodebasePopulatedInterface, BeforeFileAnalysisInterface
{
    private const CLI_ONLY_BASES = [
        Command::class,
        Migration::class,
        Seeder::class,
    ];

    private const MIGRATION_LOWER = 'illuminate\\database\\migrations\\migration';

    /** Real path of the booted app's `routes/console.php`, null when it does not exist. */
    private static ?string $consoleRoutesFile = null;

    public static function init(Application $app): void
    {
        $path = \realpath($app->basePath('routes' . \DIRECTORY_SEPARATOR . 'console.php'));
        self::$consoleRoutesFile = $path === false ? null : $path;
    }

    public static function reset(): void
    {
        self::$consoleRoutesFile = null;
    }

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

    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $filePath = $event->getStatementsSource()->getFilePath();

        if (
            $filePath === self::$consoleRoutesFile
            || self::returnsAnonymousMigration($event->getStmts(), null, $filePath, $event->getCodebase())
        ) {
            $event->markFileAsCliOnly();
        }
    }

    /**
     * @param array<Stmt> $stmts
     */
    private static function returnsAnonymousMigration(
        array $stmts,
        ?string $namespace,
        string $filePath,
        Codebase $codebase,
    ): bool {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                if (self::returnsAnonymousMigration($stmt->stmts, $stmt->name?->toString(), $filePath, $codebase)) {
                    return true;
                }

                continue;
            }

            if (!$stmt instanceof Stmt\Return_ || !$stmt->expr instanceof New_ || !$stmt->expr->class instanceof Stmt\Class_) {
                continue;
            }

            // The name Psalm gave the anonymous class when it scanned this file.
            $className = ClassAnalyzer::getAnonymousClassName($stmt->expr->class, new Aliases($namespace), $filePath);

            try {
                $storage = $codebase->classlike_storage_provider->get($className);
            } catch (\InvalidArgumentException) {
                return false;
            }

            return isset($storage->parent_classes[self::MIGRATION_LOWER]);
        }

        return false;
    }
}
