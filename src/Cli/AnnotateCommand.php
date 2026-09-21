<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the `{{-- @var --}}` declarations a Blade template is missing, taking the types from the
 * `view()` call sites that render it.
 *
 * Runs Psalm as a child process rather than doing its own parse: the call sites' types only exist
 * inside an analysis, and inferring them a second way would mean guessing. Two flags are forced on
 * that child and cannot be overridden, because the pass is unsound without them:
 *
 *  - `--threads=1`, since plugin statics collected in a forked analysis worker never reach the
 *    parent process, where the write runs;
 *  - the control file in the environment, which is the entire gate on writing to the source tree.
 *    An ordinary `vendor/bin/psalm` run has none and can never annotate anything.
 */
#[AsCommand(name: 'blade:annotate', description: "Declare a Blade template's variables from the view() call sites that render it.")]
final class AnnotateCommand extends Command
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
        // Psalm owns its own option set; forward its flags instead of rejecting them.
        $this->ignoreValidationErrors();

        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the changes as a unified diff instead of writing them.');
        $this->addArgument(
            'psalm-args',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Flags and arguments forwarded verbatim to the psalm binary (e.g. -c psalm.xml).',
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

            return Command::FAILURE;
        }

        $controlFile = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate');

        if ($controlFile === false) {
            $io->error('Unable to create the control file the annotate pass reports through.');

            return Command::FAILURE;
        }

        try {
            $dryRun = $input->hasParameterOption('--dry-run', true);
            \file_put_contents($controlFile, (string) \json_encode(['dryRun' => $dryRun]));

            $exitCode = $this->runPsalm($psalmBin, $cwd, $controlFile);

            return $this->report($io, $controlFile, $exitCode);
        } finally {
            @\unlink($controlFile);
        }
    }

    private function runPsalm(string $psalmBin, string $cwd, string $controlFile): int
    {
        $command = [\PHP_BINARY, $psalmBin, ...$this->forwardedArguments()];

        // getenv() with no argument so the child keeps the caller's whole environment: proc_open
        // REPLACES it rather than extending it, and Psalm reads PATH and XDG_CACHE_HOME from there.
        $env = \getenv();
        $env[AnnotateRequest::ENV_VAR] = $controlFile;

        $process = \proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, $cwd, $env);

        return \is_resource($process) ? \proc_close($process) : Command::FAILURE;
    }

    private function report(SymfonyStyle $io, string $controlFile, int $exitCode): int
    {
        $raw = @\file_get_contents($controlFile);

        // The shape is this plugin's own, written back by AnnotateRequest::publish() in the child.
        // The is_array() guard below is the real check: a child that died mid-run leaves the
        // request JSON the command itself wrote, which carries no `changed` key.
        /** @psalm-var array{dryRun?: bool, changed?: array<string, list<string>>, failures?: array<string, string>, diff?: string}|null $result */
        $result = \is_string($raw) ? \json_decode($raw, true) : null;

        $changed = \is_array($result) ? $result['changed'] ?? null : null;

        if ($changed === null) {
            $io->error('The analysis never reached the annotate pass.');
            $io->writeln('  Blade analysis has to be enabled and bootable: check `<blade enabled="true" />` in your Psalm config, and run `vendor/bin/psalm` without `--no-progress` to see why it degraded.');

            return $exitCode === 0 ? Command::FAILURE : $exitCode;
        }

        $io->newLine();

        foreach ($result['failures'] ?? [] as $path => $reason) {
            $io->warning(\sprintf('Skipped %s: %s', $path, $reason));
        }

        if ($changed === []) {
            $io->success('Every template already declares the variables it reads.');

            return Command::SUCCESS;
        }

        if (($result['dryRun'] ?? false) === true) {
            $io->writeln(\rtrim($result['diff'] ?? ''));
            $io->newLine();
            $io->success(\sprintf('%d template(s) would be annotated. Re-run without --dry-run to write them.', \count($changed)));

            return Command::SUCCESS;
        }

        foreach ($changed as $path => $names) {
            $io->writeln(\sprintf('  %s (%s)', $path, \implode(', ', $names)));
        }

        $io->success(\sprintf('%d template(s) annotated.', \count($changed)));

        return Command::SUCCESS;
    }

    /**
     * Tokens to forward to psalm, sliced from the raw `$_SERVER['argv']` for the same reason as
     * {@see AnalyzeCommand::forwardedArguments()}: Symfony binds `--flags` as options, never into a
     * declared argument.
     *
     * `--dry-run` is this command's own and is dropped. Any `--threads` the caller passed is
     * dropped too, and replaced with the single thread the pass requires — appending a second one
     * instead would leave PHP's getopt handing Psalm an array for a scalar option.
     *
     * Public (not private) so it is unit-testable: CommandTester cannot set argv.
     *
     * @param list<string>|null $argv Raw argv override; defaults to the process argv. Exposed for tests.
     *
     * @return list<string>
     */
    public function forwardedArguments(?array $argv = null): array
    {
        $argv ??= $_SERVER['argv'] ?? [];

        $tokens = \array_slice($argv, 1);

        if (isset($tokens[0]) && $tokens[0] === $this->getName()) {
            \array_shift($tokens);
        }

        $forwarded = [];
        $skipValue = false;

        foreach ($tokens as $token) {
            if ($skipValue) {
                $skipValue = false;

                continue;
            }

            if ($token === '--threads') {
                $skipValue = true; // the space-separated value form

                continue;
            }

            if ($token === '--dry-run' || \str_starts_with($token, '--threads=')) {
                continue;
            }

            $forwarded[] = $token;
        }

        $forwarded[] = '--threads=1';

        return $forwarded;
    }
}
