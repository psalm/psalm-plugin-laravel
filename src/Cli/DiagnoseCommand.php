<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\Report;
use Psalm\LaravelPlugin\Cli\Diagnose\TipsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a runtime introspection report so users (and maintainers triaging
 * bug reports) can see which Laravel kernel the plugin booted against and
 * which Composer / PHP versions it sees.
 *
 * Inspired by `phpstan diagnose`. See {@link https://github.com/psalm/psalm-plugin-laravel/issues/952}.
 *
 * Exits with `Command::SUCCESS` when the plugin booted as expected, and
 * `Command::FAILURE` when boot failed entirely. Soft warnings like the
 * Testbench fallback are surfaced in the report but do **not** fail.
 */
#[AsCommand(name: 'diagnose', description: 'Print runtime introspection of the plugin (versions, boot mode).')]
final class DiagnoseCommand extends Command
{
    /** Presentation labels for boot modes — purely UI text, kept out of ApplicationProvider. */
    private const BOOT_MODE_LABELS = [
        'bootstrap' => 'real bootstrap/app.php discovered',
        'testbench_fallback' => 'Testbench fallback',
    ];

    /**
     * @param Diagnostics|null  $diagnostics  Override the collector — exposed for tests so they can
     *                                        feed a deterministic in-memory report without booting Laravel.
     * @param TipsProvider|null $tipsProvider Override the tips source — exposed for tests so they can
     *                                        inject deterministic hints without touching the real PHP environment.
     */
    public function __construct(
        private readonly ?Diagnostics $diagnostics = null,
        private readonly ?TipsProvider $tipsProvider = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'tips',
            null,
            InputOption::VALUE_NEGATABLE,
            'Show the Tips section. Use --no-tips to suppress it.',
            false,
        );

        $this->addOption(
            'providers',
            null,
            InputOption::VALUE_NONE,
            'List every service provider the booted kernel registered (the default report shows only the count).',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = ($this->diagnostics ?? new Diagnostics())->collect();
        $tips = (bool) $input->getOption('tips') ? ($this->tipsProvider ?? new TipsProvider())->collect() : [];
        $io = new SymfonyStyle($input, $output);

        $this->renderReport($io, $report, $tips, (bool) $input->getOption('providers'));

        return $report->hardFailures === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $tips
     */
    private function renderReport(SymfonyStyle $io, Report $report, array $tips, bool $listProviders): void
    {
        $io->writeln('<comment>Psalm Laravel Plugin Diagnostics</comment>');
        $io->newLine();

        $this->renderSection($io, 'Versions', [
            'Plugin' => $report->pluginVersion ?? '(unknown)',
            'Psalm' => $report->psalmVersion ?? '(unknown)',
            'Laravel' => $report->laravelVersion ?? '(unknown)',
        ]);

        $this->renderSection($io, 'PHP', [
            'Runtime' => $report->phpRuntimeVersion,
            'Required' => $report->phpRequiredVersion ?? '(unknown)',
            'Analysis' => $report->phpAnalysisVersion . ' (from ' . $report->phpAnalysisSource . ')',
        ]);

        if ($report->bootMode === null) {
            $this->renderSection($io, 'Boot mode', ['Status' => '<error>FAILED</error>']);
            foreach ($report->bootstrapErrors as $error) {
                $io->writeln('  <fg=red>!</> ' . $error);
            }

            $io->newLine();
        } else {
            $this->renderSection($io, 'Boot mode', [
                'Mode' => self::BOOT_MODE_LABELS[$report->bootMode] ?? '(unknown)',
                'Path' => $report->bootPath ?? '(unknown)',
            ]);
            foreach ($report->bootstrapErrors as $error) {
                $io->writeln('  <fg=yellow>!</> Bootstrap warning: ' . $error);
            }

            if ($report->bootstrapErrors !== []) {
                $io->newLine();
            }
        }

        if ($report->hardFailures !== []) {
            $io->writeln('<error>Hard failures</error>');
            foreach ($report->hardFailures as $failure) {
                $io->writeln('  <fg=red>x</> ' . $failure);
            }

            $io->newLine();
        }

        $this->renderProviders($io, $report->loadedProviders, $listProviders);

        if ($tips !== []) {
            $io->writeln('<info>Tips</info>');
            foreach ($tips as $tip) {
                $io->writeln('  ' . $tip);
            }

            $io->newLine();
        }
    }

    /**
     * Renders the service-provider section. The default report stays compact —
     * just the count plus a hint — because the full list runs to ~30 lines on a
     * stock Laravel app. `--providers` expands it into the complete sorted list.
     *
     * @param list<string> $providers
     */
    private function renderProviders(SymfonyStyle $io, array $providers, bool $listProviders): void
    {
        $count = \count($providers);

        if (!$listProviders) {
            $this->renderSection($io, 'Providers', [
                'Loaded' => $count . ' (use --providers to list them)',
            ]);
            return;
        }

        $io->writeln('<info>Providers</info>');
        $io->writeln('  Loaded  ' . $count);
        foreach ($providers as $provider) {
            $io->writeln('    - ' . $provider);
        }

        $io->newLine();
    }

    /**
     * Renders `Title` + a left-padded, right-aligned key/value list. Cheaper
     * vertical space than `$io->definitionList()` (no table borders).
     *
     * @param array<string, string> $rows
     */
    private function renderSection(SymfonyStyle $io, string $title, array $rows): void
    {
        $io->writeln('<info>' . $title . '</info>');
        if ($rows === []) {
            $io->newLine();
            return;
        }

        $width = \max(\array_map(\strlen(...), \array_keys($rows)));
        foreach ($rows as $key => $value) {
            $io->writeln(\sprintf('  %-' . $width . 's  %s', $key, $value));
        }

        $io->newLine();
    }
}
