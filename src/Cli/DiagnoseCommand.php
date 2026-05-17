<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\JsonRenderer;
use Psalm\LaravelPlugin\Cli\Diagnose\MarkdownRenderer;
use Psalm\LaravelPlugin\Cli\Diagnose\TextRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a runtime introspection report so users (and maintainers triaging
 * bug reports) can see whether the plugin booted against the user's real
 * Laravel kernel, which stub directories activated, which handlers loaded,
 * and so on.
 *
 * Inspired by `phpstan diagnose`. See {@link https://github.com/psalm/psalm-plugin-laravel/issues/952}.
 *
 * Exits with `Command::SUCCESS` when every section resolved as expected,
 * and `Command::FAILURE` when one of the hard-failure conditions hit
 * (app boot failed, zero stubs resolved, zero handlers discovered).
 * Soft warnings like the Testbench fallback do **not** fail the command.
 */
#[AsCommand(
    name: 'diagnose',
    description: 'Print runtime introspection of the plugin (versions, boot mode, stubs, handlers).',
)]
final class DiagnoseCommand extends Command
{
    private const FORMAT_TEXT = 'text';

    private const FORMAT_JSON = 'json';

    private const FORMAT_MARKDOWN = 'markdown';

    /**
     * @param Diagnostics|null $diagnostics Override the collector — exposed for tests so they can
     *                                       feed a deterministic in-memory report without booting Laravel.
     */
    public function __construct(private readonly ?Diagnostics $diagnostics = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: text, json, markdown.',
            self::FORMAT_TEXT,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string) $input->getOption('format');

        if (!\in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON, self::FORMAT_MARKDOWN], true)) {
            $io->error(\sprintf(
                "Unknown --format '%s'. Expected one of: %s, %s, %s.",
                $format,
                self::FORMAT_TEXT,
                self::FORMAT_JSON,
                self::FORMAT_MARKDOWN,
            ));
            return Command::INVALID;
        }

        $report = ($this->diagnostics ?? new Diagnostics())->collect();

        $rendered = match ($format) {
            self::FORMAT_TEXT => (new TextRenderer())->render($report),
            self::FORMAT_JSON => (new JsonRenderer())->render($report),
            self::FORMAT_MARKDOWN => (new MarkdownRenderer())->render($report),
        };

        $output->write($rendered);

        return $report['hard_failures'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
