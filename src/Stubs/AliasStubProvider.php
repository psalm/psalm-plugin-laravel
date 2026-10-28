<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Stubs;

use Illuminate\Foundation\AliasLoader;
use Psalm\LaravelPlugin\Internal\AtomicFileWriter;
use Psalm\Plugin\RegistrationInterface;

/**
 * Generates and registers a stub file with one `class_alias(\<FQCN>::class, '<Alias>');` call for each alias
 * declared by the booted Laravel app's {@see AliasLoader}, the same call AliasLoader makes at runtime.
 *
 * `class_alias()` models every target kind (class, interface, enum) and namespaced aliases alike, where
 * `class <Alias> extends <FQCN> {}` could not (an enum or interface cannot be a parent class). Psalm
 * records the alias, not a class, so the alias storage is completed by
 * {@see \Psalm\LaravelPlugin\Handlers\Facades\ClassAliasStorageHandler}.
 *
 * Reflects the actual aliases for the project (`config/app.php` aliases plus package
 * discovery), not just Laravel's hardcoded defaults, so plugin support follows whatever
 * the application has registered at runtime.
 *
 * Targets are never autoloaded or reflected here: the stub only names them.
 *
 * Mirrors the {@see CarbonStubProvider} shape: one `register()` call writes any
 * derived files and adds them to the Psalm registration in a single step.
 *
 * @internal
 */
final class AliasStubProvider
{
    /** @var array<string, string> alias => target, exactly the aliases written to the stub this invocation */
    private static array $aliases = [];

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return self::$aliases;
    }

    public static function reset(): void
    {
        self::$aliases = [];
    }

    public static function register(RegistrationInterface $registration, string $location): void
    {
        /** @var array<string, string> $loaderAliases */
        $loaderAliases = AliasLoader::getInstance()->getAliases();
        $stub = "<?php\n\n";
        self::reset();

        // Psalm's scanner strips leading backslashes from class names too.
        $normalized = [];
        foreach ($loaderAliases as $alias => $target) {
            $normalized[\ltrim($alias, '\\')] = \ltrim($target, '\\');
        }

        $lowercased = [];
        foreach ($normalized as $alias => $target) {
            $lowercased[\strtolower($alias)] = \strtolower($target);
        }

        foreach ($normalized as $alias => $target) {
            // Self-aliases (`Introspect => Introspect`) are no-ops; cycle members would send Psalm's
            // recursive getUnAliasedName(), which has no cycle guard, into infinite recursion.
            if (\strtolower($alias) === \strtolower($target) || self::isInCycle(\strtolower($alias), $lowercased)) {
                continue;
            }

            self::$aliases[$alias] = $target;
            $stub .= "class_alias(\\{$target}::class, " . \var_export($alias, true) . ");\n";
        }

        // Unchanged aliases skip the write; the atomic write keeps this read from seeing a partial file.
        if (@\file_get_contents($location) !== $stub) {
            $failure = AtomicFileWriter::write($location, $stub);

            // A concurrent run may have written the same stub first, or Windows refused rename()
            // over a file another process holds open: only a still-wrong file is an error.
            if ($failure !== null && @\file_get_contents($location) !== $stub) {
                throw new \RuntimeException(
                    "Failed to write alias stub file to '{$location}': {$failure}. "
                    . 'Check that the directory exists and is writable.',
                );
            }
        }

        $registration->addStubFile($location);
    }

    /**
     * Whether following alias => target through the map leads back to $start.
     *
     * @param array<string, string> $lowercased lowercased alias => lowercased target
     *
     * @psalm-pure
     */
    private static function isInCycle(string $start, array $lowercased): bool
    {
        $seen = [];
        $current = $lowercased[$start];

        while (isset($lowercased[$current]) && !isset($seen[$current])) {
            if ($current === $start) {
                return true;
            }

            $seen[$current] = true;
            $current = $lowercased[$current];
        }

        return false;
    }
}
