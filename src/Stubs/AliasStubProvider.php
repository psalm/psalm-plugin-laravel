<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Stubs;

use Illuminate\Foundation\AliasLoader;
use Psalm\LaravelPlugin\Internal\AtomicFileWriter;
use Psalm\Plugin\RegistrationInterface;

/**
 * Generates and registers a `class <Alias> extends <FQCN> {}` stub file for each alias
 * declared by the booted Laravel app's {@see AliasLoader}.
 *
 * Reflects the actual aliases for the project (`config/app.php` aliases plus package
 * discovery), not just Laravel's hardcoded defaults, so plugin support follows whatever
 * the application has registered at runtime.
 *
 * Mirrors the {@see CarbonStubProvider} shape: one `register()` call writes any
 * derived files and adds them to the Psalm registration in a single step.
 *
 * @internal
 */
final class AliasStubProvider
{
    public static function register(RegistrationInterface $registration, string $location): void
    {
        /** @var array<string, class-string> $aliases */
        $aliases = AliasLoader::getInstance()->getAliases();
        $stub = "<?php\n\n";

        foreach ($aliases as $alias => $fqcn) {
            // Skip namespaced aliases — `class Some\Name extends ...` is invalid PHP
            // without a namespace block
            if (\str_contains($alias, '\\')) {
                continue;
            }

            $stub .= "class {$alias} extends \\{$fqcn} {}\n";
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
}
