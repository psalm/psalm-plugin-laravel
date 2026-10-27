<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Stubs;

use Illuminate\Foundation\AliasLoader;
use Psalm\LaravelPlugin\Internal\AtomicFileWriter;
use Psalm\Plugin\RegistrationInterface;

/**
 * Generates and registers a `class <Alias> extends <FQCN> {}` stub file for each alias
 * declared by the booted Laravel app's {@see AliasLoader}. An alias of an enum, which cannot be
 * extended, is written as a `class_alias()` call instead and its storage is completed by
 * {@see \Psalm\LaravelPlugin\Handlers\Facades\EnumAliasStorageHandler}.
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
    /** @var array<string, class-string> alias => enum, the aliases written as `class_alias()` this invocation */
    private static array $enumAliases = [];

    /**
     * @return array<string, class-string>
     */
    public static function enumAliases(): array
    {
        return self::$enumAliases;
    }

    public static function reset(): void
    {
        self::$enumAliases = [];
    }

    public static function register(RegistrationInterface $registration, string $location): void
    {
        /** @var array<string, class-string> $aliases */
        $aliases = AliasLoader::getInstance()->getAliases();
        $stub = "<?php\n\n";
        self::reset();

        foreach ($aliases as $alias => $fqcn) {
            // Skip namespaced aliases — `class Some\Name extends ...` is invalid PHP
            // without a namespace block
            if (\str_contains($alias, '\\')) {
                continue;
            }

            if (self::isEnum($fqcn)) {
                self::$enumAliases[$alias] = $fqcn;
                $stub .= "class_alias(\\{$fqcn}::class, " . \var_export($alias, true) . ");\n";

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

    /**
     * An enum cannot be a parent class, so `class Alias extends \Enum {}` leaves `Alias::Case` undefined.
     * Psalm models a literal `class_alias()` call (the call AliasLoader makes at runtime) instead.
     *
     * `enum_exists()` autoloads the target, which adds no new load: FacadeMapProvider::init() already runs
     * `is_subclass_of()` over every alias target, inside `catch (\Throwable)`, before this provider runs.
     * A target whose autoload throws keeps the `extends` line rather than breaking the boot.
     */
    private static function isEnum(string $fqcn): bool
    {
        try {
            return \enum_exists($fqcn);
        } catch (\Throwable) {
            return false;
        }
    }
}
