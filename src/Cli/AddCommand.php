<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiTargetRegistry;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\UnknownCiTargetException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs Psalm integration assets into the current project.
 *
 * Currently supports CI workflow installation: `ci` (auto-detect the
 * project's CI provider) and explicit provider names such as `github`.
 *
 * Writes are atomic (tmp file + rename) so a killed process cannot leave a
 * half-written workflow that would then fail CI on the next push.
 */
#[AsCommand(
    name: 'add',
    description: 'Wire psalm-plugin-laravel into your project (currently: CI workflows).',
)]
final class AddCommand extends Command
{
    public function __construct(
        /**
         * Override the CI target registry. Tests use this to inject fake
         * adapters; production falls back to the default registry, which is
         * also the single seam for adding new CI providers.
         */
        private readonly ?CiTargetRegistry $registry = null,
        /**
         * Override the target directory; defaults to the process CWD.
         * Exposed for tests.
         */
        private readonly ?string $workingDirectory = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'target',
            InputArgument::REQUIRED,
            'What to install: "ci" (auto-detect) or an explicit provider name such as "github".',
        );
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite the destination file without prompting.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cwd = $this->workingDirectory ?? \getcwd();
        if ($cwd === false) {
            $io->error('Unable to determine the current working directory.');
            return Command::FAILURE;
        }

        // Symfony's InputArgument::REQUIRED guarantees a non-null string reaches
        // execute(); the assertion documents the contract for Psalm and catches
        // any future misconfiguration of the argument declaration.
        $target = $input->getArgument('target');
        \assert(\is_string($target));

        $registry = $this->registry ?? CiTargetRegistry::default();

        try {
            $ciTarget = $registry->resolve($target, $cwd);
        } catch (UnknownCiTargetException $unknownCiTargetException) {
            $io->error($unknownCiTargetException->getMessage());
            return Command::FAILURE;
        }

        try {
            $plan = $ciTarget->plan($cwd);
        } catch (\RuntimeException $runtimeException) {
            // Surfaces a corrupt plugin install (missing bundled template);
            // keep the original message because it already names the offending path.
            $io->error($runtimeException->getMessage());
            return Command::FAILURE;
        }

        $io->section(\sprintf('Target: %s', $ciTarget->displayName()));
        $action = $plan->targetExists ? 'UPDATE' : 'CREATE';
        $io->writeln(\sprintf('  %s %s', $action, $plan->path));

        if ($plan->targetExists && $input->getOption('force') !== true) {
            $overwrite = $io->confirm(
                \sprintf('%s already exists. Overwrite?', $plan->path),
                false,
            );

            if (! $overwrite) {
                $io->note('Left existing file untouched.');
                return Command::SUCCESS;
            }
        }

        $writeResult = $this->writeAtomically($plan->path, $plan->contents);
        if ($writeResult !== null) {
            $io->error($writeResult);
            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote %s.', $plan->path));
        $io->writeln('Next step: commit this file and push to trigger the workflow.');

        return Command::SUCCESS;
    }

    /**
     * Writes $contents to $path via a temporary file + rename so the
     * destination never exists in a half-written state. Returns null on
     * success, or a user-facing error message on failure.
     *
     * Using atomic writes matters here because the workflow file is read by
     * CI on the very next push — a truncated YAML would produce a confusing
     * "invalid workflow" error rather than a clean re-run after fix.
     */
    private function writeAtomically(string $path, string $contents): ?string
    {
        $parentDir = \dirname($path);
        if (! \is_dir($parentDir) && ! @\mkdir($parentDir, 0755, true) && ! \is_dir($parentDir)) {
            return \sprintf('Failed to create directory %s%s', $parentDir, $this->lastErrorSuffix());
        }

        $tmp = $path . '.psalm-laravel.tmp';
        $bytes = @\file_put_contents($tmp, $contents);
        if ($bytes === false) {
            // Capture suffix before unlink so the tmp removal does not clobber
            // error_get_last() with its own success/failure status.
            $suffix = $this->lastErrorSuffix();
            @\unlink($tmp);
            return \sprintf('Failed to write %s%s', $tmp, $suffix);
        }

        if (! @\rename($tmp, $path)) {
            $suffix = $this->lastErrorSuffix();
            @\unlink($tmp);
            return \sprintf('Failed to finalize write to %s%s', $path, $suffix);
        }

        return null;
    }

    private function lastErrorSuffix(): string
    {
        $error = \error_get_last();
        return isset($error['message']) ? ': ' . $error['message'] : '';
    }
}
