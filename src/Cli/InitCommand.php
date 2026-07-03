<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a Laravel-tailored psalm.xml into the current working directory.
 *
 * Deliberately does NOT boot Psalm or Laravel: it must stay safe to run when
 * psalm.xml is broken or missing, which is exactly when users reach for it.
 *
 * @psalm-type ComposerJson = array{
 *     require?: array<string, string>,
 *     'require-dev'?: array<string, string>,
 *     autoload?: array{'psr-4'?: array<string, string|list<string>>},
 *     config?: array{'vendor-dir'?: string},
 * }
 */
#[AsCommand(name: 'init', description: 'Generate a Laravel-tailored psalm.xml in the current directory.')]
final class InitCommand extends Command
{
    private const DEFAULT_ERROR_LEVEL = '4';

    /**
     * Config file names Psalm itself recognises, in the same precedence order
     * Psalm uses when locating a project's config. `psalm.xml` wins over
     * `psalm.xml.dist` when both are present.
     */
    private const PSALM_CONFIG_FILENAMES = ['psalm.xml', 'psalm.xml.dist'];

    /** Conventional Laravel app directories. Only emitted if present on disk. */
    private const LARAVEL_APP_DIRS = ['app', 'bootstrap', 'config', 'database', 'lang', 'routes'];

    /** Conventional Laravel entry-point files. Only emitted if present. */
    private const LARAVEL_APP_FILES = ['public/index.php', 'artisan'];

    /**
     * Ignore-target candidates. Only emitted if present on disk.
     *
     * Deliberately excludes `packages/` and `nova-components/`: `<ignoreFiles>`
     * only subtracts from `<projectFiles>`, and init never emits a projectFiles
     * root that contains them — so ignoring them is inert for app layouts and
     * actively disables analysis on monorepos whose PSR-4 source lives under
     * `packages/*\/src` (scanned for reflection, never analysed). See #1213.
     */
    private const IGNORE_DIRS = ['bootstrap/cache', 'storage', 'vendor'];

    /** One indent level. Matches the template heredoc's per-nesting whitespace. */
    private const TAB = '    ';

    private const PSALM_XML_TEMPLATE = <<<'XML'
        <?xml version="1.0"?>
        <psalm
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns="https://getpsalm.org/schema/config"
            xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
            errorLevel="{{LEVEL}}"
            findUnusedCode="false"
            ensureOverrideAttribute="false"
            runTaintAnalysis="true"
        >
            <projectFiles>
        {{PROJECT_FILES}}
            </projectFiles>

            <plugins>
                <!-- All Psalm Laravel options: https://github.com/psalm/psalm-plugin-laravel/blob/master/docs/config.md -->
                <pluginClass class="Psalm\LaravelPlugin\Plugin">
                    <resolveDynamicWhereClauses value="true" />
                    <findMissingTranslations value="false" />
                    <findMissingViews value="false" />
                </pluginClass>
            </plugins>

            <issueHandlers>
                <ClassMustBeFinal errorLevel="info"/>
                <ImplicitToStringCast errorLevel="info"/>
                <MissingAbstractPureAnnotation errorLevel="info"/>
                <MissingClosureReturnType errorLevel="info"/>
                <MissingImmutableAnnotation errorLevel="info"/>
                <MissingInterfaceImmutableAnnotation errorLevel="info"/>
                <MissingOverrideAttribute errorLevel="info"/>
                <MissingPureAnnotation errorLevel="info"/>
                <RedundantCast errorLevel="info"/>
                <RedundantCondition errorLevel="info"/>
                <UnnecessaryVarAnnotation errorLevel="suppress"/>
            </issueHandlers>

            <forbiddenFunctions>
                <function name="var_dump" />
                <function name="dd" />
            </forbiddenFunctions>
        </psalm>

        XML;

    /**
     * @param string|null $workingDirectory Test seam; overrides process CWD when injected.
     */
    public function __construct(private readonly ?string $workingDirectory = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite an existing psalm.xml without prompting.',
        );
        $this->addOption(
            'level',
            'l',
            InputOption::VALUE_REQUIRED,
            'Psalm errorLevel (1 = strictest, 8 = most lenient).',
            self::DEFAULT_ERROR_LEVEL,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $level = $this->validateLevel($input, $io);
        if ($level === null) {
            return Command::FAILURE;
        }

        $cwd = $this->workingDirectory ?? \getcwd();
        if ($cwd === false) {
            $io->error('Unable to determine the current working directory.');
            return Command::FAILURE;
        }

        $cwdNormalized = \rtrim($cwd, \DIRECTORY_SEPARATOR);
        // Reuse the existing config path (psalm.xml or psalm.xml.dist) so the
        // generated file lands where Psalm and the user already expect it,
        // rather than silently creating a second config file alongside the old one.
        $existingPath = $this->findExistingConfig($cwdNormalized);
        $targetPath = $existingPath ?? $cwdNormalized . \DIRECTORY_SEPARATOR . 'psalm.xml';
        if (!$this->shouldWrite($targetPath, $existingPath !== null, $input, $io)) {
            return Command::SUCCESS;
        }

        $composer = $this->readComposerJson($cwd);
        $hasPhpunitPlugin = $this->composerHasPackage($composer, 'psalm/plugin-phpunit');
        [$directories, $files] = $this->detectSourceRoots($cwd, $composer, $hasPhpunitPlugin);
        $ignores = $this->detectIgnoreDirs($cwd, $composer);

        $contents = \strtr(self::PSALM_XML_TEMPLATE, [
            '{{LEVEL}}' => $level,
            '{{PROJECT_FILES}}' => $this->buildProjectFiles($directories, $files, $ignores, $hasPhpunitPlugin),
        ]);

        if (!$this->writeFile($targetPath, $contents, $io)) {
            return Command::FAILURE;
        }

        $this->reportSuccess($io, $cwd, $targetPath, $existingPath !== null, $level, $directories, $files);
        return Command::SUCCESS;
    }

    /** Returns the validated level string, or null after emitting an error. */
    private function validateLevel(InputInterface $input, SymfonyStyle $io): ?string
    {
        // VALUE_REQUIRED with a string default; Symfony's mixed return signature
        // is wider than the actual runtime contract — assert what we know.
        /** @psalm-var string|null $level */
        $level = $input->getOption('level');
        if ($level !== null && \preg_match('/^[1-8]$/', $level) === 1) {
            return $level;
        }

        $io->error(\sprintf(
            'Invalid --level value %s. Must be an integer between 1 (strictest) and 8 (most lenient).',
            $level !== null ? "'{$level}'" : 'of unexpected type',
        ));
        return null;
    }

    /**
     * Return the first existing Psalm config path under $cwd, following Psalm's
     * own precedence (psalm.xml beats psalm.xml.dist). Returns null when neither exists.
     */
    private function findExistingConfig(string $cwd): ?string
    {
        foreach (self::PSALM_CONFIG_FILENAMES as $name) {
            $candidate = $cwd . \DIRECTORY_SEPARATOR . $name;
            if (\file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** True when no Psalm config exists, --force is set, or the user confirms overwrite. */
    private function shouldWrite(string $targetPath, bool $exists, InputInterface $input, SymfonyStyle $io): bool
    {
        if (!$exists || $input->getOption('force') === true) {
            return true;
        }

        $filename = \basename($targetPath);
        $overwrite = $io->confirm(\sprintf('%s already exists at %s. Overwrite?', $filename, $targetPath), false);
        if (!$overwrite) {
            $io->note(\sprintf('Left existing %s untouched.', $filename));
        }

        return $overwrite;
    }

    private function writeFile(string $targetPath, string $contents, SymfonyStyle $io): bool
    {
        // Suppress the warning so we can surface error_get_last() ourselves;
        // a bare "failed to write" message sends users hunting the wrong cause.
        $bytes = @\file_put_contents($targetPath, $contents);
        if ($bytes !== false) {
            return true;
        }

        $error = \error_get_last();
        $reason = isset($error['message']) ? ': ' . $error['message'] : '';
        $io->error(\sprintf('Failed to write %s%s', $targetPath, $reason));
        return false;
    }

    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    private function reportSuccess(
        SymfonyStyle $io,
        string $cwd,
        string $targetPath,
        bool $existedBefore,
        string $level,
        array $directories,
        array $files,
    ): void {
        $io->success(\sprintf(
            '%s %s.',
            $existedBefore ? 'Overwrote existing config at' : 'Wrote new config to',
            $targetPath,
        ));
        $io->writeln('Plugin enabled: <info>Psalm\\LaravelPlugin\\Plugin</info>');
        $io->writeln(\sprintf('Error level: <info>%s</info>', $level));
        $io->writeln('Scanned paths:');
        foreach ([...$directories, ...$files] as $path) {
            $io->writeln(\sprintf('  - <info>%s</info>', $path));
        }

        // Mirror inline XML hint when tests/ exists but isn't scanned,
        // usually because psalm/plugin-phpunit isn't installed.
        if (!\in_array('tests', $directories, true) && \is_dir($cwd . \DIRECTORY_SEPARATOR . 'tests')) {
            $io->writeln('');
            $io->writeln(
                '<comment>Note:</comment> tests/ dir skipped. To scan it: <info>composer require --dev psalm/plugin-phpunit</info> and add tests dir to <projectFiles>',
            );
        }

        $io->writeln('');
        $io->writeln('Next step: run <info>vendor/bin/psalm-laravel analyze</info>');
    }

    /**
     * Resolve scannable roots from project layout. Splits into Laravel-app vs
     * package mode based on the presence of artisan. Falls back to the Laravel
     * default if both branches produce empty results.
     *
     * @param ComposerJson|null $composer
     * @return array{0: list<string>, 1: list<string>} [directories, files]
     */
    private function detectSourceRoots(string $cwd, ?array $composer, bool $hasPhpunitPlugin): array
    {
        [$directories, $files] = \is_file($cwd . \DIRECTORY_SEPARATOR . 'artisan')
            ? $this->detectLaravelAppRoots($cwd)
            : $this->detectPackageRoots($cwd, $composer);

        // Ultimate fallback so the template still validates.
        if ($directories === [] && $files === []) {
            $directories = self::LARAVEL_APP_DIRS;
            $files = self::LARAVEL_APP_FILES;
        }

        // Scanning tests/ without psalm/plugin-phpunit floods output with PHPUnit-magic
        // false positives, so opt in only when the plugin is already wired up.
        if ($hasPhpunitPlugin
            && \is_dir($cwd . \DIRECTORY_SEPARATOR . 'tests')
            && !\in_array('tests', $directories, true)
        ) {
            $directories[] = 'tests';
        }

        return [$directories, $files];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function detectLaravelAppRoots(string $cwd): array
    {
        $directories = [];
        foreach (self::LARAVEL_APP_DIRS as $dir) {
            if (\is_dir($cwd . \DIRECTORY_SEPARATOR . $dir)) {
                $directories[] = $dir;
            }
        }

        $files = [];
        foreach (self::LARAVEL_APP_FILES as $file) {
            if (\is_file($cwd . \DIRECTORY_SEPARATOR . $file)) {
                $files[] = $file;
            }
        }

        return [$directories, $files];
    }

    /**
     * @param ComposerJson|null $composer
     * @return array{0: list<string>, 1: list<string>}
     */
    private function detectPackageRoots(string $cwd, ?array $composer): array
    {
        $directories = [];
        foreach ($this->extractComposerAutoloadDirs($composer) as $dir) {
            if (\is_dir($cwd . \DIRECTORY_SEPARATOR . $dir) && !\in_array($dir, $directories, true)) {
                $directories[] = $dir;
            }
        }

        // Package configs commonly live in config/ even without an artisan entrypoint.
        if (\is_dir($cwd . \DIRECTORY_SEPARATOR . 'config') && !\in_array('config', $directories, true)) {
            $directories[] = 'config';
        }

        // Last-resort fallback when composer.json is missing or has no PSR-4 mapping.
        if ($directories === [] && \is_dir($cwd . \DIRECTORY_SEPARATOR . 'src')) {
            $directories[] = 'src';
        }

        return [$directories, []];
    }

    /**
     * Filter IGNORE_DIRS to entries present under $cwd. Honours composer's
     * `config.vendor-dir` so projects that relocate vendor/ still ignore the
     * right path.
     *
     * @param ComposerJson|null $composer
     * @return list<string>
     */
    private function detectIgnoreDirs(string $cwd, ?array $composer): array
    {
        $vendorDir = $this->resolveVendorDir($composer);

        $present = [];
        foreach (self::IGNORE_DIRS as $dir) {
            // Swap the canonical 'vendor' token for the project-specific path; other entries pass through.
            $candidate = $dir === 'vendor' ? $vendorDir : $dir;
            if (\is_dir($cwd . \DIRECTORY_SEPARATOR . $candidate) && !\in_array($candidate, $present, true)) {
                $present[] = $candidate;
            }
        }

        return $present;
    }

    /**
     * Decode composer.json once. Returns null on any read/decode failure so
     * callers can keep using the project as if composer.json weren't there.
     *
     * @return ComposerJson|null
     */
    private function readComposerJson(string $cwd): ?array
    {
        $path = $cwd . \DIRECTORY_SEPARATOR . 'composer.json';
        if (!\is_file($path)) {
            return null;
        }

        $contents = @\file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            // Composer schema is documented and stable; trust the declared shape
            // for the keys we read. Unknown JSON content is rejected by is_array.
            /** @psalm-var ComposerJson|null $decoded */
            $decoded = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * True when $package is listed in `require` or `require-dev`. Version
     * constraints are ignored: presence is the only signal we need.
     *
     * @param ComposerJson|null $composer
     * @psalm-pure
     */
    private function composerHasPackage(?array $composer, string $package): bool
    {
        if ($composer === null) {
            return false;
        }

        return \array_key_exists($package, $composer['require'] ?? [])
            || \array_key_exists($package, $composer['require-dev'] ?? []);
    }

    /**
     * Read composer's relocated vendor directory if configured, else 'vendor'.
     *
     * @param ComposerJson|null $composer
     * @psalm-pure
     */
    private function resolveVendorDir(?array $composer): string
    {
        $configured = $composer['config']['vendor-dir'] ?? null;
        if ($configured === null || $configured === '') {
            return 'vendor';
        }

        // Strip leading `./` and trailing slashes. Composer accepts both forms,
        // but psalm.xml paths are composer-root-relative without a prefix.
        $normalised = \rtrim(\preg_replace('#^\./#', '', $configured) ?? $configured, '/');

        return $normalised === '' ? 'vendor' : $normalised;
    }

    /**
     * Extract `autoload.psr-4` directories from composer.json. Order preserved,
     * duplicates removed, trailing slashes stripped.
     *
     * @param ComposerJson|null $composer
     * @return list<string>
     * @psalm-pure
     */
    private function extractComposerAutoloadDirs(?array $composer): array
    {
        $psr4 = $composer['autoload']['psr-4'] ?? [];

        $dirs = [];
        foreach ($psr4 as $paths) {
            $items = \is_string($paths) ? [$paths] : $paths;
            foreach ($items as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                $dir = \rtrim($candidate, '/');
                if ($dir === '' || \in_array($dir, $dirs, true)) {
                    continue;
                }

                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * Render the inner <projectFiles> body from already-resolved roots. The
     * returned string is pre-indented for direct substitution into the heredoc.
     *
     * @param list<string> $directories
     * @param list<string> $files
     * @param list<string> $ignores
     * @psalm-pure
     */
    private function buildProjectFiles(array $directories, array $files, array $ignores, bool $hasPhpunitPlugin): string
    {
        // Two-level indent matches the heredoc: <projectFiles> at one TAB, its
        // children at two, ignoreFiles' grandchildren at three.
        $itemIndent = self::TAB . self::TAB;
        $ignoreItemIndent = $itemIndent . self::TAB;

        $lines = [];
        foreach ($directories as $dir) {
            $lines[] = \sprintf('%s<directory name="%s"/>', $itemIndent, $dir);
        }

        // Nudge toward psalm/plugin-phpunit only when not already wired up;
        // once present, scanning tests/ is handled in detectSourceRoots().
        if (! $hasPhpunitPlugin) {
            $lines[] = $itemIndent . '<!-- composer require psalm/plugin-phpunit psalm/plugin-mockery for the full tests support -->';
            $lines[] = $itemIndent . '<!-- <directory name="tests"/>-->';
            $lines[] = '';
        }

        if ($files !== []) {
            $lines[] = '';
            foreach ($files as $file) {
                $lines[] = \sprintf('%s<file name="%s"/>', $itemIndent, $file);
            }
        }

        // Only emit ignores that exist on disk: listing non-existent paths
        // clutters the config and confuses users skimming psalm.xml.
        if ($ignores !== []) {
            $lines[] = $itemIndent . '<ignoreFiles allowMissingFiles="true">';
            foreach ($ignores as $dir) {
                $lines[] = \sprintf('%s<directory name="%s"/>', $ignoreItemIndent, $dir);
            }

            $lines[] = $itemIndent . '</ignoreFiles>';
        }

        return \implode("\n", $lines);
    }
}
