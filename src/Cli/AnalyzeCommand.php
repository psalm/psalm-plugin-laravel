<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Config\PluginOverrides;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        // source the command reads — scanArguments() reads the raw argv.
        $this->addArgument(
            'psalm-args',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Flags and arguments forwarded verbatim to the psalm binary (e.g. --set-baseline=psalm-baseline.xml).',
        );

        // Declared for `analyze --help` only: scanArguments() consumes it from raw argv, and the command
        // hands it to psalm in PSALM_LARAVEL_OPTIONS because psalm rejects unknown flags.
        $this->addOption(
            'plugin-option',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Override a plugin setting for this run (KEY=VALUE, repeatable), over PSALM_LARAVEL_OPTIONS and psalm.xml.',
        );
        $this->setHelp(
            'Plugin option keys: ' . \implode(', ', PluginOverrides::KEYS) . ".\n"
            . 'See https://github.com/psalm/psalm-plugin-laravel/blob/4.x/docs/config.md#per-run-overrides',
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

        // Checked before launch so a mistake is a clean message, not a stack trace from inside psalm.
        // Against an empty plugin element, so only the overrides are checked; the plugin checks psalm.xml.
        try {
            $scan = $this->scanArguments();
            $env = \getenv();

            if ($scan['options'] !== []) {
                // Appended, so a CLI value beats an inherited one by last-key-wins.
                $env[PluginOverrides::ENV_VAR] = \trim(($env[PluginOverrides::ENV_VAR] ?? '') . ' ' . \implode(' ', $scan['options']));
            }

            $overrides = PluginOverrides::parse($env[PluginOverrides::ENV_VAR] ?? '');

            if ($overrides !== []) {
                PluginOverrides::configure(null, $overrides);
            }
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage());
            return Command::FAILURE;
        }

        $forwarded = $scan['forwarded'];

        // A `blade` toggle under an unchanged psalm.xml replays stale file references; see decisions.md, "Per-run overrides".
        if (isset($overrides['blade'])) {
            \array_unshift($forwarded, '--no-reference-cache');
        }

        // proc_open with an array argv runs the binary directly (no shell), so
        // forwarded tokens are passed literally and never re-interpreted.
        $command = [\PHP_BINARY, $psalmBin, ...$forwarded];

        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];
        // An explicit env array REPLACES the environment in proc_open, hence the whole getenv() is copied above.
        $process = \proc_open($command, $descriptors, $pipes, $cwd, $env);

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
     * Splits the raw `$_SERVER['argv']` into the tokens to forward to psalm and the per-run plugin
     * overrides, from `--plugin-option KEY=VALUE` and `--plugin-option=KEY=VALUE` in order. One scan
     * yields both, so the stripped tokens and the overrides cannot drift apart.
     *
     * Raw argv, not parsed input: with ignoreValidationErrors one bad option makes Symfony drop ALL
     * parsed options, and `ArgvInput::getRawTokens()` needs Symfony >= 7.1.
     * Drops argv[0] and the explicit command-name/alias token (the default-command
     * form has none, so nothing is stripped). `--plugin-option` is consumed here because psalm
     * rejects flags it does not know; scanning stops at a standalone `--`, past which every
     * token is positional, as in Symfony's own option parsing.
     *
     * Limits: `-h`/`-V` short-circuit to the wrapper's own help/version (use
     * `vendor/bin/psalm` for those); global options before the subcommand aren't
     * stripped cleanly, so pass psalm flags after `analyze`.
     *
     * Public (not private) so it is unit-testable: CommandTester can't set argv.
     *
     * @param list<string>|null $argv Raw argv override; defaults to the process argv. Exposed for tests.
     * @return array{forwarded: list<string>, options: list<string>}
     *
     * @throws \InvalidArgumentException When a `--plugin-option` value is missing, empty or contains whitespace.
     */
    public function scanArguments(?array $argv = null): array
    {
        // `argv` is absent only when `register_argc_argv` is disabled; Symfony's
        // own ArgvInput falls back the same way, so default to an empty list.
        $argv ??= $_SERVER['argv'] ?? [];

        $tokens = \array_slice($argv, 1); // drop the script path (argv[0])

        $commandNames = \array_filter([$this->getName(), ...$this->getAliases()]);
        if (isset($tokens[0]) && \in_array($tokens[0], $commandNames, true)) {
            \array_shift($tokens); // drop the explicit command-name token
        }

        $forwarded = [];
        $options = [];

        for ($index = 0, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token === '--') {
                return ['forwarded' => [...$forwarded, ...\array_slice($tokens, $index)], 'options' => $options];
            }

            if (\str_starts_with($token, '--plugin-option=')) {
                $value = \substr($token, \strlen('--plugin-option='));
            } elseif ($token === '--plugin-option') {
                $value = $tokens[++$index] ?? '-';

                // Mirrors Symfony: a following token starting with `-` is an option, not the value.
                if (\str_starts_with($value, '-')) {
                    throw new \InvalidArgumentException('--plugin-option requires a KEY=VALUE argument, e.g. --plugin-option blade=true.');
                }
            } else {
                $forwarded[] = $token;
                continue;
            }

            // The value travels in the whitespace-separated PSALM_LARAVEL_OPTIONS, where it must stay one token.
            if (\preg_match('/^\S+$/', $value) !== 1) {
                throw new \InvalidArgumentException("--plugin-option '{$value}' must be a non-empty KEY=VALUE without whitespace; set such a value in psalm.xml.");
            }

            $options[] = $value;
        }

        return ['forwarded' => $forwarded, 'options' => $options];
    }
}
