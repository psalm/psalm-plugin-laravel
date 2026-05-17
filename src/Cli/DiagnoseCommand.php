<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\TextRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
#[AsCommand(
    name: 'diagnose',
    description: 'Print runtime introspection of the plugin (versions, boot mode).',
)]
final class DiagnoseCommand extends Command
{
    /**
     * @param Diagnostics|null $diagnostics Override the collector — exposed for tests so they can
     *                                       feed a deterministic in-memory report without booting Laravel.
     */
    public function __construct(private readonly ?Diagnostics $diagnostics = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = ($this->diagnostics ?? new Diagnostics())->collect();
        $output->write((new TextRenderer())->render($report));

        return $report['hard_failures'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
