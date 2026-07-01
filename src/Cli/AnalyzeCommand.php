<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs Psalm on the current project by delegating to the `psalm` binary.
 *
 * Locates `vendor/bin/psalm` relative to the current working directory and
 * execs it, forwarding any extra flags/arguments verbatim and passing the
 * child's exit code through. Does not boot Psalm or Laravel itself — the child
 * process owns analysis.
 *
 * The passthrough is what lets the curated CLI cover the whole Psalm surface,
 * e.g. `psalm-laravel analyze --set-baseline=psalm-baseline.xml`, instead of
 * making users drop to `vendor/bin/psalm` for anything that needs a flag.
 */
#[AsCommand(name: 'analyze', description: 'Run Psalm analysis on the current project.', aliases: ['analyse'])]
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
    protected function configure(): void
    {
        // Psalm owns its own option set, so stop Symfony Console from rejecting
        // Psalm-native flags it doesn't recognise (e.g. `--set-baseline`) as
        // "option does not exist" before the command can forward them.
        $this->ignoreValidationErrors();

        // Declared so `analyze --help` documents the passthrough. Symfony binds
        // `--flags` as options (never into this argument), so it is not the
        // source the command reads — forwardedArguments() reads the raw argv.
        $this->addArgument(
            'psalm-args',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Flags and arguments forwarded verbatim to the psalm binary (e.g. --set-baseline=psalm-baseline.xml).',
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

        $psalmBin = \rtrim($cwd, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR . 'vendor'
            . \DIRECTORY_SEPARATOR . 'bin'
            . \DIRECTORY_SEPARATOR . 'psalm';

        if (!\is_file($psalmBin)) {
            $io->error(\sprintf('Could not find %s. Install Psalm with `composer require --dev vimeo/psalm`.', $psalmBin));
            $this->writeLaunchDiagnostics($io, $psalmBin, $cwd);
            return Command::FAILURE;
        }

        // proc_open with an array argv runs the binary directly (no shell), so
        // forwarded tokens are passed literally and never re-interpreted.
        $command = [\PHP_BINARY, $psalmBin, ...$this->forwardedArguments()];

        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];
        $process = \proc_open($command, $descriptors, $pipes, $cwd);

        if (!\is_resource($process)) {
            $io->error('Failed to launch Psalm.');
            $this->writeLaunchDiagnostics($io, $psalmBin, $cwd);
            return Command::FAILURE;
        }

        return \proc_close($process);
    }

    /**
     * Prints the PHP binary, Psalm binary path and existence, and working
     * directory — the environment facts needed to triage a "Psalm won't start"
     * report without a back-and-forth (#1195).
     *
     * Uses plain writeln(), not $io->error()'s styled block: that block
     * word-wraps at the terminal width, which would break a long path.
     */
    private function writeLaunchDiagnostics(SymfonyStyle $io, string $psalmBin, string $cwd): void
    {
        $io->writeln(\sprintf('  PHP binary:        %s', \PHP_BINARY));
        $io->writeln(\sprintf('  Psalm binary:      %s (exists: %s)', $psalmBin, \is_file($psalmBin) ? 'yes' : 'no'));
        $io->writeln(\sprintf('  Working directory: %s', $cwd));
        $io->newLine();
    }

    /**
     * Tokens to forward to psalm, sliced from the raw `$_SERVER['argv']`.
     *
     * Raw argv, not parsed input: Symfony binds `--flags` as options (not into a
     * declared argument), and `ArgvInput::getRawTokens()` needs Symfony >= 7.1.
     * Drops argv[0] and the explicit command-name/alias token (the default-command
     * form has none, so nothing is stripped).
     *
     * Limits: `-h`/`-V` short-circuit to the wrapper's own help/version (use
     * `vendor/bin/psalm` for those); global options before the subcommand aren't
     * stripped cleanly, so pass psalm flags after `analyze`.
     *
     * Public (not private) so it is unit-testable: CommandTester can't set argv.
     *
     * @param list<string>|null $argv Raw argv override; defaults to the process argv. Exposed for tests.
     * @return list<string>
     */
    public function forwardedArguments(?array $argv = null): array
    {
        // `argv` is absent only when `register_argc_argv` is disabled; Symfony's
        // own ArgvInput falls back the same way, so default to an empty list.
        $argv ??= $_SERVER['argv'] ?? [];

        $tokens = \array_slice($argv, 1); // drop the script path (argv[0])

        $commandNames = \array_filter([$this->getName(), ...$this->getAliases()]);
        if (isset($tokens[0]) && \in_array($tokens[0], $commandNames, true)) {
            \array_shift($tokens); // drop the explicit command-name token
        }

        return $tokens;
    }
}
