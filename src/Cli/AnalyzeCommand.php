<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs Psalm on the current project by delegating to the `psalm` binary.
 *
 * Locates `vendor/bin/psalm` relative to the current working directory and
 * execs it, passing the child's exit code through. Does not boot Psalm or
 * Laravel itself — the child process owns analysis.
 */
#[AsCommand(
    name: 'analyze',
    aliases: ['analyse'],
    description: 'Run Psalm analysis on the current project.',
)]
final class AnalyzeCommand extends Command
{
    /**
     * @param string|null $workingDirectory Override the target directory; defaults to the process CWD.
     *                                      Exposed for tests.
     */
    public function __construct(private readonly ?string $workingDirectory = null)
    {
        parent::__construct();
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

        $psalmBin = \rtrim($cwd, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR . 'vendor'
            . \DIRECTORY_SEPARATOR . 'bin'
            . \DIRECTORY_SEPARATOR . 'psalm';

        if (! \is_file($psalmBin)) {
            $io->error(\sprintf(
                'Could not find %s. Install Psalm with `composer require --dev vimeo/psalm`.',
                $psalmBin,
            ));
            return Command::FAILURE;
        }

        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];
        $process = \proc_open([$psalmBin], $descriptors, $pipes, $cwd);

        if (! \is_resource($process)) {
            $io->error('Failed to launch Psalm.');
            return Command::FAILURE;
        }

        return \proc_close($process);
    }
}
