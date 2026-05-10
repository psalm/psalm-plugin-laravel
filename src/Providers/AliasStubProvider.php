<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Foundation\AliasLoader;
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

        $result = \file_put_contents($location, $stub);

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to write alias stub file to '{$location}'. "
                . 'Check that the directory exists and is writable.',
            );
        }

        $registration->addStubFile($location);
    }
}
