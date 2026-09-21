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
     * Psalm's long options that REQUIRE a value, so the token after one is that value and never a
     * path to check. Mirrors the single-colon entries of `Psalm\Internal\Cli\Psalm::LONG_OPTIONS`;
     * the double-colon ones are left out because getopt() binds an optional value only when it is
     * attached (`--find-unused-code=x`), leaving a following token positional.
     */
    private const VALUE_TAKING_LONG_OPTIONS = [
        'config',
        'disable-extension',
        'dump-taint-graph',
        'error-level',
        'find-references-to',
        'generate-json-map',
        'generate-stubs',
        'memory-limit',
        'output-format',
        'php-version',
        'plugin',
        'report',
        'report-show-info',
        'root',
        'scan-threads',
        'show-info',
        'threads',
        'use-baseline',
    ];

    /**
     * @param string|null       $workingDirectory Override the target directory; defaults to the process CWD.
     *                                            Exposed for tests.
     * @param list<string>|null $argvOverride     Override the raw argv the psalm arguments are read
     *                                            from; defaults to the process argv. Exposed for
     *                                            tests, which run under PHPUnit's own argv.
     */
    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly ?array $argvOverride = null,
    ) {
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

        $pathLimiting = $this->pathLimitingArguments();

        if ($pathLimiting !== []) {
            $io->error(\sprintf(
                'blade:annotate analyses the whole project, so it cannot take a path: %s.',
                \implode(', ', $pathLimiting),
            ));
            $io->writeln('  A type is only written when every call site agrees on it, and a call site in a file the run skipped cannot disagree.');

            return Command::FAILURE;
        }

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
            \file_put_contents($controlFile, (string) \json_encode([
                AnnotateRequest::MARKER => 1,
                'dryRun' => $dryRun,
            ]));

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

    /**
     * The raw argv tokens addressed to psalm: everything after the script path, less the command
     * name when it was spelled out (the default-command form has none).
     *
     * @param list<string>|null $argv
     *
     * @return list<string>
     */
    private function rawTokens(?array $argv): array
    {
        $tokens = \array_slice($argv ?? $this->argvOverride ?? $_SERVER['argv'] ?? [], 1);

        if (isset($tokens[0]) && $tokens[0] === $this->getName()) {
            \array_shift($tokens);
        }

        return $tokens;
    }

    private function report(SymfonyStyle $io, string $controlFile, int $exitCode): int
    {
        $raw = @\file_get_contents($controlFile);

        // The shape is this plugin's own, written back by AnnotateRequest::publish() in the child.
        // The is_array() guard below is the real check: a child that died mid-run leaves the
        // request JSON the command itself wrote, which carries no `changed` key.
        /** @psalm-var array{dryRun?: bool, changed?: array<string, list<string>>, failures?: array<string, string>, diff?: string, error?: string}|null $result */
        $result = \is_string($raw) ? \json_decode($raw, true) : null;

        $error = \is_array($result) ? $result['error'] ?? null : null;

        if ($error !== null) {
            $io->error("The annotate pass wrote nothing: {$error}");

            return Command::FAILURE;
        }

        $changed = \is_array($result) ? $result['changed'] ?? null : null;

        if ($changed === null) {
            $io->error('The analysis never reached the annotate pass.');
            $io->writeln('  Blade analysis has to be enabled and bootable: check `<blade enabled="true" />` in your Psalm config, and run `vendor/bin/psalm` without `--no-progress` to see why it degraded.');

            return $exitCode === 0 ? Command::FAILURE : $exitCode;
        }

        $io->newLine();

        $failures = $result['failures'] ?? [];

        foreach ($failures as $path => $reason) {
            $io->warning(\sprintf('Skipped %s: %s', $path, $reason));
        }

        $dryRun = ($result['dryRun'] ?? false) === true;

        if ($changed !== []) {
            if ($dryRun) {
                $io->writeln(\rtrim($result['diff'] ?? ''));
                $io->newLine();
            } else {
                foreach ($changed as $path => $names) {
                    $io->writeln(\sprintf('  %s (%s)', $path, \implode(', ', $names)));
                }
            }
        }

        // Ahead of both success branches, so a dry run fails too: its diff is then not what a write
        // would produce, and a caller gating on the exit code would ship the difference unnoticed.
        if ($failures !== []) {
            $io->error($changed === []
                ? \sprintf('%d template(s) could not be annotated.', \count($failures))
                : \sprintf(
                    '%d template(s) %s, %d could not be.',
                    \count($changed),
                    $dryRun ? 'would be annotated' : 'annotated',
                    \count($failures),
                ));

            return Command::FAILURE;
        }

        if ($changed === []) {
            $io->success('Every template already declares the variables it reads.');

            return Command::SUCCESS;
        }

        $io->success($dryRun
            ? \sprintf('%d template(s) would be annotated. Re-run without --dry-run to write them.', \count($changed))
            : \sprintf('%d template(s) annotated.', \count($changed)));

        return Command::SUCCESS;
    }

    /**
     * Forwarded tokens that would limit the run to some paths: a bare path, or `-f`'s value.
     *
     * Producer agreement is a whole-project claim. Under `psalm app/A.php` a competing call site in
     * `app/B.php` is never analysed, so a type two call sites disagree on is written as if they
     * agreed — the one way this command can put a wrong annotation in a template.
     *
     * Public (not private) so it is unit-testable: CommandTester cannot set argv.
     *
     * @param list<string>|null $argv Raw argv override; defaults to the process argv. Exposed for tests.
     *
     * @return list<string>
     */
    public function pathLimitingArguments(?array $argv = null): array
    {
        $offending = [];
        $expectsValue = false;

        foreach ($this->rawTokens($argv) as $token) {
            if ($expectsValue) {
                $expectsValue = false;

                continue;
            }

            if ($token === '-f') {
                // Its value is not skipped: it is the path itself, and naming it in the error is
                // more use to the caller than naming the flag alone.
                $offending[] = $token;

                continue;
            }

            // `-c psalm.xml`, `-r <root>` and the long options that require a value take one that is
            // not a path to check; every other bare token is one Psalm adds to its paths-to-check list.
            if ($token === '-c' || $token === '-r' || $this->takesASeparateValue($token)) {
                $expectsValue = true;

                continue;
            }

            if (!\str_starts_with($token, '-')) {
                $offending[] = $token;
            }
        }

        return $offending;
    }

    /** Whether the token is a long option spelled without its value, which the next token supplies. */
    private function takesASeparateValue(string $token): bool
    {
        return \str_starts_with($token, '--')
            && \in_array(\substr($token, 2), self::VALUE_TAKING_LONG_OPTIONS, true);
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
        $forwarded = [];
        $skipValue = false;

        foreach ($this->rawTokens($argv) as $token) {
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

        // BEFORE the forwarded tokens, not after: PHP's getopt() stops at the first positional
        // argument, so a --threads=1 trailing one is never parsed and the child forks.
        return ['--threads=1', ...$forwarded];
    }
}
