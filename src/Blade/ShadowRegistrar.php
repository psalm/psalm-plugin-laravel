<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The registrations a compiled template needs from Psalm, kept behind an interface because the
 * real implementation needs a live `ProjectAnalyzer` that a unit test cannot build.
 *
 * @psalm-mutable Interface does not require implementations to be immutable.
 */
interface ShadowRegistrar
{
    /**
     * Makes issues located in these Blade templates reportable.
     *
     * Impure by contract: both registrations write into the running analysis.
     *
     * @param list<string> $templatePaths `.blade.php` paths, never shadow paths
     *
     * @return bool false when Psalm's project-file list could not be extended; the caller must then
     *              register no shadows at all
     *
     * @psalm-impure
     */
    public function markTemplatesReportable(array $templatePaths): bool;

    /**
     * @param list<string> $shadowPaths compiled PHP files, never `.blade.php` paths
     *
     * @psalm-impure
     */
    public function registerShadowsForAnalysis(array $shadowPaths): void;

    /**
     * Queues classes for scanning that no project file may ever reference in code position: the
     * shadow prelude names them only in stacked one-line `@var` docblocks, and PhpParser attaches
     * every stacked comment to one `Stmt\Nop`, whose `Node::getDocComment()` searches backward and
     * returns only the last comment. The last ambient entry declares `$loop`, an object shape naming
     * no class, and further `@var mixed` lines can follow it — so all five ambient FQCNs go unqueued
     * and report UndefinedDocblockClass the first time nothing else in the project names them in code.
     *
     * @param list<string> $classNames fully-qualified, `\`-prefixed or not
     *
     * @psalm-impure
     */
    public function queueClassLikesForScanning(array $classNames): void;

    /**
     * Queues class-name CANDIDATES harvested from a shadow's string literals (#1505): a vendor
     * directive that compiles a class name into a string argument rather than code position
     * (`app('Vendor\Package\Class')::method()`) names a class Psalm's own scanner never sees. Unlike
     * {@see self::queueClassLikesForScanning()}, these are speculative — most string literals name
     * no class at all — so the implementation must gate each candidate on being independently
     * resolvable before queueing it, never queue unconditionally.
     *
     * @param list<string> $candidates class-like names harvested from shadow string literals,
     *                     `\`-prefixed or not
     *
     * @psalm-impure
     */
    public function queueResolvableClassLikesForScanning(array $candidates): void;

    /**
     * Queues whole FILES for deep scanning, for the helper files the booted app `include`d (#1551).
     * Nothing in the project references them in code position, so without this they get no
     * `FileStorage` at all and {@see RuntimeHelperVisibility} has nothing to read.
     *
     * @param list<string> $paths absolute paths to existing PHP files
     *
     * @psalm-impure
     */
    public function queueFilesForScanning(array $paths): void;

    /**
     * Reports a template the compiler threw on, at its line 1, as `BladeCompileError`. Only called
     * after {@see self::markTemplatesReportable()} succeeded for it.
     *
     * @param string $source  the template source the compiler was given
     * @param string $message the compiler's cause, on one line
     *
     * @psalm-impure
     */
    public function reportCompileFailure(string $templatePath, string $source, string $message): void;

    /**
     * The file that already defines `$className` independently of any template (#2126): the one
     * Composer would load it from, or the one the booted application loaded it from. A template
     * declaring the same FQCN would make Psalm raise DuplicateClass and switch off the analysis of
     * BOTH declarations, so the caller withholds the template's own.
     *
     * @param string $className fully-qualified, without a leading backslash
     *
     * @return string|null null when nothing outside the templates defines it (a class PHP itself
     *                     declares has no file and counts as undefined here)
     *
     * @psalm-impure
     */
    public function existingClassFile(string $className): ?string;

    /**
     * Makes Psalm read `$contents` instead of the shadow on disk, which stays untouched: the shadow
     * cache is keyed by template content and must never encode what else the project declares.
     *
     * @param string $contents the shadow with every line break kept, so its line map still applies
     *
     * @psalm-impure
     */
    public function overrideShadowContents(string $shadowPath, string $contents): void;

    /**
     * Reports `DuplicateClass` on a template line for a class whose declaration the shadow no
     * longer carries. Only called after {@see self::markTemplatesReportable()} succeeded.
     *
     * @param string       $source     the template source, or empty when it could not be read
     * @param string       $className  the declared FQCN
     * @param string       $firstFile  the file that defines it first: a project file or another template
     * @param list<string> $suppressed issue types the template suppressed on that line
     *
     * @psalm-impure
     */
    public function reportClassCollision(
        string $templatePath,
        string $source,
        int $line,
        string $className,
        string $firstFile,
        array $suppressed,
    ): void;
}
