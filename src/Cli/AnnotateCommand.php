<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the `@var` docblock declarations a Blade template is missing, taking the types from the
 * `view()` call sites that render it.
 *
 * Runs Psalm as a child process rather than doing its own parse: the call sites' types only exist
 * inside an analysis, and inferring them a second way would mean guessing. The control file in the
 * child's environment is the entire gate on writing to the source tree: an ordinary
 * `vendor/bin/psalm` run has none and can never annotate anything. Under it the plugin pins the
 * analysis to one process (plugin statics collected in a forked worker never reach the parent,
 * where the write runs); scanning keeps the caller's `--threads`.
 *
 * The child's issue report (hundreds of findings on a real project) would bury this command's own
 * summary, so its stdout is captured to a file and shown only when the pass never ran; progress
 * on stderr stays live.
 */
#[AsCommand(name: 'blade:annotate', description: "Declare a Blade template's variables from the view() call sites that render it.")]
final class AnnotateCommand extends Command
{
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
        // Not `--diff`/`--no-diff`: both are Psalm's own diff-mode flags, and unknown tokens are forwarded to Psalm.
        $this->addOption('no-diffs', null, InputOption::VALUE_NONE, 'List the changed templates instead of printing the unified diff.');
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
        $psalmOutput = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-out');

        if ($controlFile === false || $psalmOutput === false) {
            $io->error('Unable to create the control file the annotate pass reports through.');

            return Command::FAILURE;
        }

        try {
            $dryRun = $input->hasParameterOption('--dry-run', true);
            \file_put_contents($controlFile, (string) \json_encode([
                AnnotateRequest::MARKER => 1,
                'dryRun' => $dryRun,
            ]));

            $exitCode = $this->runPsalm($psalmBin, $cwd, $controlFile, $psalmOutput);

            return $this->report($io, $controlFile, $exitCode, $psalmOutput, !$input->hasParameterOption('--no-diffs', true));
        } finally {
            @\unlink($controlFile);
            @\unlink($psalmOutput);
        }
    }

    private function runPsalm(string $psalmBin, string $cwd, string $controlFile, string $psalmOutput): int
    {
        $command = [\PHP_BINARY, $psalmBin, ...$this->forwardedArguments()];

        // getenv() with no argument so the child keeps the caller's whole environment: proc_open
        // REPLACES it rather than extending it, and Psalm reads PATH and XDG_CACHE_HOME from there.
        $env = \getenv();
        $env[AnnotateRequest::ENV_VAR] = $controlFile;

        // Progress goes to stderr and stays live; the issue report on stdout goes to a file, because
        // a whole-project run prints hundreds of findings that bury this command's own summary.
        $process = \proc_open($command, [0 => \STDIN, 1 => ['file', $psalmOutput, 'w'], 2 => \STDERR], $pipes, $cwd, $env);

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

    private function report(SymfonyStyle $io, string $controlFile, int $exitCode, ?string $psalmOutput = null, bool $showDiffs = true): int
    {
        $raw = @\file_get_contents($controlFile);

        // The shape is this plugin's own, written back by AnnotateRequest::publish() in the child.
        // The is_array() guard below is the real check: a child that died mid-run leaves the
        // request JSON the command itself wrote, which carries no `changed` key.
        /** @psalm-var array{dryRun?: bool, changed?: array<string, list<string>>, failures?: array<string, string>, diff?: string, unknown?: array<string, list<string>>, unreadable?: array<string, array{site: string, names: list<string>}>, disagreed?: array<string, array<string, list<string>>>, parseErrors?: array<string, string>, error?: string}|null $result */
        $result = \is_string($raw) ? \json_decode($raw, true) : null;

        $error = \is_array($result) ? $result['error'] ?? null : null;

        if ($error !== null) {
            $io->error("The annotate pass wrote nothing: {$error}");

            return Command::FAILURE;
        }

        $changed = \is_array($result) ? $result['changed'] ?? null : null;

        if ($changed === null) {
            // The pass never ran, so Psalm's own output is the only clue why: show it now.
            $captured = $psalmOutput === null ? '' : \trim((string) @\file_get_contents($psalmOutput));

            if ($captured !== '') {
                $io->writeln(OutputFormatter::escape($captured));
            }

            $io->error('The analysis never reached the annotate pass.');
            // A Blade disable publishes its reason as `error` above, so this is a crash or a run with no template.
            $io->writeln('  Psalm stopped before it, or found no Blade template to annotate: see its output above.');

            return $exitCode === 0 ? Command::FAILURE : $exitCode;
        }

        $io->newLine();

        $failures = $result['failures'] ?? [];

        foreach ($failures as $path => $reason) {
            $io->warning(\sprintf('Skipped %s: %s', $path, $reason));
        }

        // The views such a file names are listed as unreadable below; a file with no view literal in it
        // changes nothing there, so the report is the only place the parse error shows up.
        foreach ($result['parseErrors'] ?? [] as $path => $reason) {
            $io->warning(OutputFormatter::escape(\sprintf(
                'Psalm could not parse %s (%s). A view it names is left undeclared, since a call site there could not be read.',
                $path,
                $reason,
            )));
        }

        $dryRun = ($result['dryRun'] ?? false) === true;

        if ($changed !== []) {
            if ($showDiffs) {
                $io->writeln($this->colorizedDiff($result['diff'] ?? ''));
                $io->newLine();
            } else {
                foreach ($changed as $path => $names) {
                    $io->writeln(\sprintf('  %s (%s)', $path, \implode(', ', $names)));
                }
            }
        }

        $unknown = $result['unknown'] ?? [];

        if ($unknown !== []) {
            $io->writeln('  Undeclared, type unknown (no call site types them; declare them by hand):');

            foreach ($unknown as $path => $names) {
                $io->writeln(\sprintf('  %s (%s)', $path, \implode(', ', $names)));
            }

            $io->newLine();
        }

        $unreadable = $result['unreadable'] ?? [];

        if ($unreadable !== []) {
            // Its own list: "no call site types them" would be false here, and the site is what to change.
            $io->writeln('  Undeclared, a call site could not be read (pass a literal array there, or declare them by hand):');

            foreach ($unreadable as $path => ['site' => $site, 'names' => $names]) {
                $io->writeln(\sprintf('  %s (%s)%s', $path, \implode(', ', $names), $site === '' ? '' : ", at {$site}"));
            }

            $io->newLine();
        }

        $disagreed = $result['disagreed'] ?? [];

        if ($disagreed !== []) {
            $io->writeln('  Undeclared, call sites disagree (declare them by hand):');

            foreach ($disagreed as $view => $names) {
                foreach ($names as $name => $types) {
                    $io->writeln(OutputFormatter::escape(\sprintf('  %s $%s: %s', $view, $name, \implode(' vs ', $types))));
                }
            }

            $io->newLine();
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
            $io->success(match (true) {
                $unknown === [] && $unreadable === [] && $disagreed === [] => 'Every template already declares the variables it reads.',
                $unreadable === [] && $disagreed === [] => \sprintf('Nothing to annotate: %d template(s) read only names no call site types.', \count($unknown)),
                default => 'Nothing to annotate: every undeclared name is listed above.',
            });

            return Command::SUCCESS;
        }

        $io->success($dryRun
            ? \sprintf('%d template(s) would be annotated. Re-run without --dry-run to write them.', \count($changed))
            : \sprintf('%d template(s) annotated.', \count($changed)));

        return Command::SUCCESS;
    }

    /**
     * The unified diff with git's colours: removed lines red, added green, hunk headers cyan.
     * Every line is escaped first, so template markup like `<x-card>` is never read as a console
     * style tag; undecorated output (a pipe, a file) gets the plain diff back, applicable as is.
     * Split on "\n" alone, so a CRLF template's "\r" stays in its lines.
     */
    private function colorizedDiff(string $diff): string
    {
        $lines = [];

        foreach (\explode("\n", \rtrim($diff, "\n")) as $line) {
            $escaped = OutputFormatter::escape($line);

            $lines[] = match (true) {
                \str_starts_with($line, '--- '), \str_starts_with($line, '+++ ') => "<options=bold>{$escaped}</>",
                \str_starts_with($line, '@@') => "<fg=cyan>{$escaped}</>",
                \str_starts_with($line, '+') => "<fg=green>{$escaped}</>",
                \str_starts_with($line, '-') => "<fg=red>{$escaped}</>",
                default => $escaped,
            };
        }

        return \implode("\n", $lines);
    }

    /**
     * Forwarded tokens that would limit the run to some paths: a bare path, `-f`'s value, or `-f=<path>`.
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

            // `-f=<path>` is the only attached spelling Psalm accepts for `-f`. For a bare `-f` the
            // value is not skipped: it is the path itself, and naming it in the error is
            // more use to the caller than naming the flag alone.
            if ($token === '-f' || \str_starts_with($token, '-f=')) {
                $offending[] = $token;

                continue;
            }

            // Mirrors the values `CliUtils::getPathsToCheck()` skips: after `-c`, `-r`, `--config`,
            // `--printer` and `--root` the next token is not a path. Psalm skips no other separate
            // value, so `--memory-limit 2G` or `--report-show-info app/A.php` leaves the follower in
            // its paths-to-check list (a path, or a "Cannot locate" error), and so it is refused here.
            if (\in_array($token, ['-c', '-r', '--config', '--printer', '--root'], true)) {
                $expectsValue = true;

                continue;
            }

            if (!\str_starts_with($token, '-')) {
                $offending[] = $token;
            }
        }

        return $offending;
    }

    /**
     * Tokens to forward to psalm, sliced from the raw `$_SERVER['argv']` for the same reason as
     * {@see AnalyzeCommand::scanArguments()}: Symfony binds `--flags` as options, never into a
     * declared argument.
     *
     * Only `--dry-run` and `--no-diffs`, this command's own, are dropped. `--threads` passes through
     * and sizes the scan; the plugin pins the analysis itself to one process.
     *
     * Public (not private) so it is unit-testable: CommandTester cannot set argv.
     *
     * @param list<string>|null $argv Raw argv override; defaults to the process argv. Exposed for tests.
     *
     * @return list<string>
     */
    public function forwardedArguments(?array $argv = null): array
    {
        return \array_values(\array_filter(
            $this->rawTokens($argv),
            static fn(string $token): bool => $token !== '--dry-run' && $token !== '--no-diffs',
        ));
    }
}
