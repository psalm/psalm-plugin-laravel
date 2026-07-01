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
 */
#[AsCommand(name: 'init', description: 'Generate a Laravel-tailored psalm.xml in the current directory.')]
final class InitCommand extends Command
{
    private const DEFAULT_ERROR_LEVEL = '4';

    /** Conventional Laravel app directories. Only emitted if present on disk. */
    private const LARAVEL_APP_DIRS = ['app', 'bootstrap', 'config', 'database', 'lang', 'routes'];

    /** Conventional Laravel entry-point files. Only emitted if present. */
    private const LARAVEL_APP_FILES = ['public/index.php', 'artisan'];

    /** Ignore-target candidates. Only emitted if present on disk. */
    private const IGNORE_DIRS = ['bootstrap/cache', 'storage', 'vendor', 'packages', 'nova-components'];

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
        $existingPath = PsalmConfigLocator::locate($cwdNormalized);
        $targetPath = $existingPath ?? $cwdNormalized . \DIRECTORY_SEPARATOR . 'psalm.xml';
        if (!$this->shouldWrite($targetPath, $existingPath !== null, $input, $io)) {
            return Command::SUCCESS;
        }

        // A broken composer.json must not STOP init from writing a config —
        // that is exactly the situation this command exists to recover from —
        // but the user should still be told why autoload/vendor-dir detection
        // was skipped, rather than silently guessing wrong.
        try {
            $composerJson = ComposerJson::read($cwd);
        } catch (\Throwable $throwable) {
            $composerJson = null;
            $io->warning(\sprintf(
                'composer.json exists but could not be parsed (%s); skipping autoload/vendor-dir detection.',
                $throwable->getMessage(),
            ));
        }

        $hasPhpunitPlugin = $composerJson?->hasPackage('psalm/plugin-phpunit') ?? false;
        [$directories, $files] = $this->detectSourceRoots($cwd, $composerJson, $hasPhpunitPlugin);
        $ignores = $this->detectIgnoreDirs($cwd, $composerJson);

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
     * @return array{0: list<string>, 1: list<string>} [directories, files]
     */
    private function detectSourceRoots(string $cwd, ?ComposerJson $composerJson, bool $hasPhpunitPlugin): array
    {
        [$directories, $files] = \is_file($cwd . \DIRECTORY_SEPARATOR . 'artisan')
            ? $this->detectLaravelAppRoots($cwd)
            : $this->detectPackageRoots($cwd, $composerJson);

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
     * @return array{0: list<string>, 1: list<string>}
     */
    private function detectPackageRoots(string $cwd, ?ComposerJson $composerJson): array
    {
        $directories = [];
        foreach ($composerJson?->autoloadPsr4Dirs() ?? [] as $dir) {
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
     * @return list<string>
     */
    private function detectIgnoreDirs(string $cwd, ?ComposerJson $composerJson): array
    {
        $vendorDir = $composerJson?->vendorDir() ?? 'vendor';

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
