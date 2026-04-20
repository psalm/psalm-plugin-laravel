<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Plugin\EventHandler\AfterAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;

/**
 * Emits plugin-related counts when Psalm runs with --stats.
 *
 * Psalm does not expose the --stats flag to plugins via Config/Codebase,
 * so we sniff $_SERVER['argv'] directly.
 *
 * The AfterAnalysis hook dispatches from IssueBuffer::finish() before Psalm's
 * own --stats block, so on a merged terminal the plugin stats appear after
 * the issue list and just before the 'N errors found' separator.
 *
 * Output goes through Psalm's progress stream (typically STDERR). This keeps
 * machine-readable stdout report output clean. Under --no-progress the
 * underlying VoidProgress is a no-op, matching the user's intent to suppress
 * progress-like output.
 *
 * @internal
 */
final class StatsHandler implements AfterAnalysisInterface
{
    #[\Override]
    public static function afterAnalysis(AfterAnalysisEvent $event): void
    {
        if (!self::statsRequested()) {
            return;
        }

        $codebase = $event->getCodebase();
        $progress = $codebase->progress;

        $modelCount = self::countModels($codebase);

        $schema = SchemaStateProvider::getSchema();
        $tables = $schema === null ? 'N/A' : (string) \count($schema->tables);

        $progress->write("Laravel plugin stats:\n");
        $progress->write("  Models discovered: {$modelCount}\n");
        $progress->write("  Tables in schema: {$tables}\n");
    }

    /**
     * Counts concrete Model subclasses using the same filter as
     * {@see ModelRegistrationHandler::afterCodebasePopulated()} so the stat
     * mirrors the set the plugin actually registers handlers for.
     *
     * The class_exists() gate from ModelRegistrationHandler is deliberately
     * skipped here: init-time already warned on unloadable classes, and
     * re-invoking the autoloader post-analysis would duplicate warnings.
     * The small drift (unloadable Model subclasses are counted but not
     * registered) is acceptable for a discovery metric.
     *
     * @psalm-external-mutation-free
     */
    private static function countModels(Codebase $codebase): int
    {
        $count = 0;
        // parent_classes is keyed by lowercase FQN without a leading backslash
        // (the same convention every other handler in this plugin relies on;
        // see e.g. ModelRegistrationHandler, SuppressHandler).
        $modelFqcn = \strtolower(Model::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract) {
                continue;
            }

            if (!isset($storage->parent_classes[$modelFqcn])) {
                continue;
            }

            if (
                $storage->stmt_location !== null
                && ModelRegistrationHandler::isSyntheticAnonymousClassName($storage->name, $storage->stmt_location->file_path)
            ) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Psalm's CLI defines --stats as a boolean long option (no `=value` form),
     * so presence in argv is sufficient. Returns false for non-CLI invocations
     * (language server, programmatic use) where argv is not set.
     */
    private static function statsRequested(): bool
    {
        $argv = $_SERVER['argv'] ?? null;

        return \is_array($argv) && \in_array('--stats', $argv, true);
    }
}
