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
 * Intentionally does NOT boot Psalm or Laravel so it remains safe to run when
 * psalm.xml is broken or missing — the exact moment a user needs it. Does,
 * however, run `composer require` on explicit user confirmation when the
 * project has phpunit/mockery without the matching Psalm companion plugins.
 */
#[AsCommand(
    name: 'init',
    description: 'Generate a Laravel-tailored psalm.xml in the current directory.',
)]
final class InitCommand extends Command
{
    private const DEFAULT_ERROR_LEVEL = '3';

    /**
     * Placeholder for a future preset system (see #785). Today only "auto"
     * is accepted — it means "infer sensible defaults from project context"
     * (the current behavior, including companion-plugin detection).
     *
     * Values like "ci", "dev", "audit" may be added later; keeping the option
     * now lets callers pin `--preset=auto` today without a breaking change
     * when real presets ship.
     */
    private const DEFAULT_PRESET = 'auto';

    /** @var list<string> */
    private const SUPPORTED_PRESETS = ['auto'];

    private const PSALM_XML_TEMPLATE = <<<'XML'
        <?xml version="1.0"?>
        <psalm
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns="https://getpsalm.org/schema/config"
            xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
            errorLevel="{{LEVEL}}"
            findUnusedCode="false"
            ensureOverrideAttribute="false"
        >
            <projectFiles>
                <directory name="."/>
                <ignoreFiles allowMissingFiles="true">
                    <directory name="vendor"/>
                    <directory name="storage"/>
                    <directory name="bootstrap/cache"/>
                </ignoreFiles>
            </projectFiles>

            <plugins>
                <pluginClass class="Psalm\LaravelPlugin\Plugin"/>{{COMPANION_PLUGINS}}
            </plugins>

            <issueHandlers>
                <ClassMustBeFinal errorLevel="suppress"/>
                <MissingAbstractPureAnnotation errorLevel="suppress"/>
                <MissingClosureReturnType errorLevel="suppress"/>
                <MissingImmutableAnnotation errorLevel="suppress"/>
                <MissingInterfaceImmutableAnnotation errorLevel="suppress"/>
                <MissingOverrideAttribute errorLevel="suppress"/>
                <MissingPureAnnotation errorLevel="suppress"/>
                <RedundantCast errorLevel="suppress"/>
                <RedundantCondition errorLevel="suppress"/>
                <UnnecessaryVarAnnotation errorLevel="suppress"/>
            </issueHandlers>
        </psalm>

        XML;

    /**
     * @param string|null $workingDirectory Override the target directory; defaults to the process CWD.
     *                                      Exposed for tests; users interact via the CLI only.
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
        $this->addOption(
            'no-suggest',
            null,
            InputOption::VALUE_NONE,
            \sprintf(
                'Do not detect or suggest companion Psalm plugins (%s).',
                \implode(', ', \array_map(
                    static fn(CompanionPlugin $p): string => $p->pluginPackage(),
                    CompanionPlugin::cases(),
                )),
            ),
        );
        $this->addOption(
            'preset',
            'p',
            InputOption::VALUE_REQUIRED,
            \sprintf(
                'Configuration preset. Supported values: %s. More presets (ci, dev, audit) may be added later.',
                \implode(', ', self::SUPPORTED_PRESETS),
            ),
            self::DEFAULT_PRESET,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $levelOption = $input->getOption('level');
        if (! \is_string($levelOption) || \preg_match('/^[1-8]$/', $levelOption) !== 1) {
            $io->error(\sprintf(
                'Invalid --level value %s. Must be an integer between 1 (strictest) and 8 (most lenient).',
                \is_string($levelOption) ? "'{$levelOption}'" : 'of unexpected type',
            ));
            return Command::FAILURE;
        }

        $presetOption = $input->getOption('preset');
        if (! \is_string($presetOption) || ! \in_array($presetOption, self::SUPPORTED_PRESETS, true)) {
            $io->error(\sprintf(
                'Invalid --preset value %s. Supported: %s.',
                \is_string($presetOption) ? "'{$presetOption}'" : 'of unexpected type',
                \implode(', ', self::SUPPORTED_PRESETS),
            ));
            return Command::FAILURE;
        }

        $cwd = $this->workingDirectory ?? \getcwd();
        if ($cwd === false) {
            $io->error('Unable to determine the current working directory.');
            return Command::FAILURE;
        }

        $targetPath = \rtrim($cwd, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'psalm.xml';

        if (\file_exists($targetPath) && $input->getOption('force') !== true) {
            $shouldOverwrite = $io->confirm(
                \sprintf('psalm.xml already exists at %s. Overwrite?', $targetPath),
                false,
            );

            if (! $shouldOverwrite) {
                $io->note('Left existing psalm.xml untouched.');
                return Command::SUCCESS;
            }
        }

        $decisions = $this->resolveCompanionPlugins($input, $io, $cwd);

        $contents = \str_replace(
            ['{{LEVEL}}', '{{COMPANION_PLUGINS}}'],
            [$levelOption, $decisions->xmlFragment],
            self::PSALM_XML_TEMPLATE,
        );

        // Suppress the warning so we can surface error_get_last() ourselves —
        // blind "failed to write" messages send users hunting for the real cause.
        $bytes = @\file_put_contents($targetPath, $contents);
        if ($bytes === false) {
            $error = \error_get_last();
            $reason = isset($error['message']) ? ': ' . $error['message'] : '';
            $io->error(\sprintf('Failed to write %s%s', $targetPath, $reason));
            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote %s.', $targetPath));

        foreach ($decisions->confirmations as $note) {
            $io->writeln(\sprintf('  [+] %s', $note));
        }

        foreach ($decisions->hints as $hint) {
            $io->writeln($hint);
        }

        $io->writeln('Next step: run <info>vendor/bin/psalm</info>.');

        return Command::SUCCESS;
    }

    /**
     * Decide what to do about companion plugins (phpunit, mockery).
     *
     * Flow per companion (skipped entirely when --no-suggest):
     *   - dep absent                       → nothing
     *   - dep present + plugin installed   → auto-include + confirmation note
     *   - dep present + plugin missing:
     *       · interactive → prompt; on yes run composer require, then include if install succeeded
     *       · non-interactive → print a static hint so CI logs are informative
     */
    private function resolveCompanionPlugins(
        InputInterface $input,
        SymfonyStyle $io,
        string $cwd,
    ): CompanionPluginDecisions {
        if ($input->getOption('no-suggest') === true) {
            return CompanionPluginDecisions::none();
        }

        $inspector = new ComposerInspector($cwd);
        if ($inspector->parseWarning !== null) {
            // composer.json exists but is unreadable or malformed. Surface the
            // reason so the user can fix it — otherwise a broken composer.json
            // silently degrades to "no companion plugins detected", leading
            // users to file bugs about missing detection.
            $io->warning(\sprintf(
                'Skipping companion-plugin detection: %s',
                $inspector->parseWarning,
            ));
            return CompanionPluginDecisions::none();
        }

        $pluginEntries = '';
        /** @var list<string> $confirmations */
        $confirmations = [];
        /** @var list<string> $hints */
        $hints = [];

        foreach (CompanionPlugin::cases() as $plugin) {
            if (! $inspector->hasDependency($plugin->dependency())) {
                continue;
            }

            if ($inspector->hasInstalledPackage($plugin->pluginPackage())) {
                $pluginEntries .= $this->pluginClassEntry($plugin);
                $confirmations[] = $this->enabledMessage($plugin, 'already installed');
                continue;
            }

            if ($input->isInteractive() && $this->promptAndInstall($io, $plugin, $inspector, $cwd)) {
                $pluginEntries .= $this->pluginClassEntry($plugin);
                $confirmations[] = $this->enabledMessage($plugin, 'now installed');
                continue;
            }

            // Either non-interactive, or the user declined / install failed.
            // Print a hint so users (and CI logs) know about the companion.
            $hints[] = \sprintf(
                '  [i] %1$s detected. Install the Psalm %1$s plugin for type-aware analysis:',
                $plugin->friendlyName(),
            );
            $hints[] = \sprintf('      composer require --dev %s', $plugin->pluginPackage());
        }

        return new CompanionPluginDecisions($pluginEntries, $confirmations, $hints);
    }

    /** @psalm-mutation-free */
    private function enabledMessage(CompanionPlugin $plugin, string $status): string
    {
        return \sprintf(
            '%s detected and %s %s -> enabled',
            $plugin->friendlyName(),
            $plugin->pluginPackage(),
            $status,
        );
    }

    /**
     * Leading newline + 8-space indent so the entry slots neatly after the
     * Laravel pluginClass line in the template's `<plugins>` block.
     *
     * @psalm-mutation-free
     */
    private function pluginClassEntry(CompanionPlugin $plugin): string
    {
        return "\n        <pluginClass class=\"{$plugin->pluginClass()}\"/>";
    }

    /**
     * Ask the user whether to install the companion plugin and, on yes, run
     * `composer require --dev <package>` with pass-through I/O so the user
     * sees composer's prompts and output directly.
     *
     * Returns true only when, after composer exits, the package is actually
     * installed under vendor/. A non-zero composer exit, or a successful exit
     * that somehow didn't install the package, both return false and emit a
     * distinct warning so users know why the hint reappears.
     */
    private function promptAndInstall(
        SymfonyStyle $io,
        CompanionPlugin $plugin,
        ComposerInspector $inspector,
        string $cwd,
    ): bool {
        $confirmed = $io->confirm(
            \sprintf(
                '%s detected. Install %s for type-aware analysis?',
                $plugin->friendlyName(),
                $plugin->pluginPackage(),
            ),
            false,
        );

        if (! $confirmed) {
            return false;
        }

        $manualCommand = \sprintf('composer require --dev %s', $plugin->pluginPackage());
        $command = ['composer', 'require', '--dev', $plugin->pluginPackage()];
        $io->writeln(\sprintf('Running: <info>%s</info>', \implode(' ', $command)));

        // Pass-through descriptors: composer inherits our STDIN/OUT/ERR and
        // writes directly to the user's terminal. $pipes stays empty; if
        // anyone switches to captured pipes (e.g. ['pipe', 'w']), they must
        // fclose them before proc_close or the call will deadlock.
        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];
        $process = \proc_open($command, $descriptors, $pipes, $cwd);
        if (! \is_resource($process)) {
            $error = \error_get_last();
            $reason = isset($error['message']) ? ': ' . $error['message'] : '';
            $io->warning(\sprintf(
                'Failed to launch composer%s. Install manually: %s',
                $reason,
                $manualCommand,
            ));
            return false;
        }

        $exitCode = \proc_close($process);
        if ($exitCode !== 0) {
            $io->warning(\sprintf(
                'composer require exited with code %d. The package was not installed — '
                . 'check composer output above for the cause (version conflict, auth, network). '
                . 'Retry manually once resolved: %s',
                $exitCode,
                $manualCommand,
            ));
            return false;
        }

        if (! $inspector->hasInstalledPackage($plugin->pluginPackage())) {
            // Composer reported success but the package isn't on disk. Rare
            // but possible (e.g., a plugin rewriting the install path). Call
            // it out distinctly so the user doesn't assume "retry" will help.
            $io->warning(\sprintf(
                'composer reported success but %s is not under vendor/. Install manually: %s',
                $plugin->pluginPackage(),
                $manualCommand,
            ));
            return false;
        }

        return true;
    }
}
