<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a Laravel-tailored psalm.xml into the current working directory.
 *
 * Intentionally does NOT boot Psalm or Laravel so it remains safe to run when
 * psalm.xml is broken or missing — the exact moment a user needs it.
 */
#[AsCommand(
    name: 'init',
    description: 'Generate a Laravel-tailored psalm.xml in the current directory.',
)]
final class InitCommand extends Command
{
    private const PSALM_XML_TEMPLATE = <<<'XML'
        <?xml version="1.0"?>
        <psalm
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns="https://getpsalm.org/schema/config"
            xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
            errorLevel="3"
            findUnusedCode="false"
            ensureOverrideAttribute="false"
        >
            <projectFiles>
                <directory name="."/>
                <ignoreFiles allowMissingFiles="true">
                    <directory name="vendor"/>
                    <directory name="storage"/>
                    <directory name="bootstrap/cache"/>
                </ignoreFiles>
            </projectFiles>

            <plugins>
                <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
            </plugins>
        </psalm>

        XML;

    /**
     * @param string|null $workingDirectory Override the target directory; defaults to the process CWD.
     *                                      Exposed for tests; users interact via the CLI only.
     */
    public function __construct(private readonly ?string $workingDirectory = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite an existing psalm.xml without prompting.',
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

        $targetPath = \rtrim($cwd, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'psalm.xml';

        if (\file_exists($targetPath) && $input->getOption('force') !== true) {
            $shouldOverwrite = $io->confirm(
                \sprintf('psalm.xml already exists at %s. Overwrite?', $targetPath),
                false,
            );

            if (! $shouldOverwrite) {
                $io->note('Left existing psalm.xml untouched.');
                return Command::SUCCESS;
            }
        }

        // Suppress the warning so we can surface error_get_last() ourselves —
        // blind "failed to write" messages send users hunting for the real cause.
        $bytes = @\file_put_contents($targetPath, self::PSALM_XML_TEMPLATE);
        if ($bytes === false) {
            $error = \error_get_last();
            $reason = isset($error['message']) ? ': ' . $error['message'] : '';
            $io->error(\sprintf('Failed to write %s%s', $targetPath, $reason));
            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote %s.', $targetPath));
        $io->writeln('Next step: run <info>vendor/bin/psalm</info>.');

        return Command::SUCCESS;
    }
}
