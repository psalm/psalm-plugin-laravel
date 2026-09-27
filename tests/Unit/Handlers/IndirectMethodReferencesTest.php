<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\References\IndirectMethodReferenceHandler;
use Symfony\Component\Process\Process;

/**
 * Whole-project regression coverage for Laravel's indirect method references. Psalm's dead-code
 * consolidation does not inspect an explicitly passed file, so this deliberately runs a real
 * fixture project with findUnusedCode enabled, matching a consuming application's lifecycle.
 *
 * Markers are matched against the whole dead-code family, never against method findings alone:
 * when a class loses its last live reference Psalm folds all of its members into one UnusedClass
 * finding, so a `Foo::__construct` marker checked against method findings cannot fail. Method
 * markers stay class-unqualified because Psalm reports inherited and trait methods under the
 * using class, not the declaring one.
 */
#[CoversClass(IndirectMethodReferenceHandler::class)]
final class IndirectMethodReferencesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/IndirectMethodReferences';

    /** @var list<string> */
    private const DEAD_CODE = ['PossiblyUnusedMethod', 'UnusedMethod', 'UnusedConstructor', 'UnusedClass'];

    /** @var list<string> */
    private const DEAD_RETURNS = ['PossiblyUnusedReturnValue', 'UnusedReturnValue'];

    #[Test]
    public function it_reports_dead_code_only_where_laravel_cannot_dispatch(): void
    {
        $findings = $this->runPsalm(self::FIXTURE, false);
        $deadCode = $this->report($findings, self::DEAD_CODE);

        foreach ([
            '::update',                         // controller action
            '::inherited',                      // action inherited from an abstract base controller
            '::traitAction',                    // action pulled in from a trait
            '::handle',                         // console command entrypoint
            '::team',                           // relationship declared on the model itself
            '::baseTeam',                       // relationship inherited from a parent model
            '::traitTeam',                      // relationship pulled in from a trait
            'BaseController::__construct',      // container-constructed, declared on the parent
            'ConcreteController::__construct',  // container-constructed, own declaration
            // Constructors Laravel autowires out of an entrypoint signature. These classes have no
            // other reference, so a lost edge surfaces as UnusedClass rather than as the method.
            'Dependencies\UpdateDriver',        // action parameter
            'Dependencies\InvokeDependency',    // __invoke parameter (Psalm never reports __invoke itself)
            'Dependencies\CommandDependency',   // handle() parameter
            'Dependencies\OwnerDependency',     // controller constructor parameter
            'Dependencies\NestedDependency',    // resolved recursively out of OwnerDependency
            'Dependencies\InheritedActionDependency', // parameter of an inherited action
            'Dependencies\TraitActionDependency',     // parameter of a trait-provided action
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $deadCode, "Expected {$marker} to be referenced indirectly.");
        }

        foreach ([
            'Dependencies\UnusedDependency::__construct',        // referenced by nothing
            'Dependencies\UnionDependencyA::__construct',        // union parameter: not autowirable
            'Dependencies\ContractImplementation::__construct',  // only its interface is type-hinted
            'Dependencies\ProtectedDependency::__construct',     // non-public constructor
            'Dependencies\AbstractDependency::__construct',      // abstract type hint
            'Dependencies\DocblockOnlyDependency::__construct',  // docblock-only type: invisible to the container
            'Commands\ReferenceCommand::helper',                 // public command method other than handle()
            'Models\User::privateTeam',                          // non-public relationship
            'Models\User::ordinaryUnused',                       // plain model method
        ] as $marker) {
            $this->assertStringContainsString($marker, $deadCode, "Expected {$marker} to remain reportable.");
        }

        // A private controller helper is not an entrypoint. Psalm never reports the helper itself
        // (Illuminate's Controller::__call makes private methods possibly-called), so its parameter
        // staying an unused class is the only observable proof.
        $this->assertStringContainsString(
            'Dependencies\HelperDependency',
            $this->report($findings, ['UnusedClass']),
            'Expected HelperDependency to remain an unused class.',
        );

        // A public command method other than handle() is not an entrypoint either; its
        // parameter staying an unused class proves the restriction applies beyond controllers.
        $this->assertStringContainsString(
            'Dependencies\CommandHelperDependency',
            $this->report($findings, ['UnusedClass']),
            'Expected CommandHelperDependency to remain an unused class.',
        );

        // Laravel consumes what an entrypoint and a relationship return (the router, the console
        // kernel, eager loading), so those edges carry "return value used" - unlike a discarded
        // return of an ordinary public method.
        $deadReturns = $this->report($findings, self::DEAD_RETURNS);
        $this->assertStringNotContainsString('function team(', $deadReturns, 'Expected the relationship return value to read as used.');
        $this->assertStringNotContainsString('function show(', $deadReturns, 'Expected the controller action return value to read as used.');
        $this->assertStringContainsString('function discarded(', $deadReturns, 'Expected a discarded return value to remain reportable.');
    }

    /**
     * `--find-dead-code` turns reference collection on after the config is parsed, so the plugin
     * must gate handler registration on the codebase flag rather than on the config value.
     */
    #[Test]
    public function it_honors_the_cli_dead_code_override_when_config_disables_it(): void
    {
        $deadCode = $this->report(
            $this->runPsalm(self::FIXTURE, false, 'psalm-no-dead-code.xml', ['--find-dead-code']),
            self::DEAD_CODE,
        );

        $this->assertStringNotContainsString('::update', $deadCode, 'Expected the CLI override to activate the handler.');
        $this->assertStringContainsString(
            'Dependencies\UnusedDependency::__construct',
            $deadCode,
            'Expected the CLI override to actually report dead code.',
        );
    }

    #[Test]
    public function cached_runs_replay_references_after_file_changes(): void
    {
        // Keep the copy under the repository so Psalm's GitInfoCollector does not emit its
        // "not a git repository" warning, which would hide real subprocess failures on stderr.
        $fixtureDir = self::FIXTURE . '/.incremental-' . (int) \getmypid();
        $this->copyDirectory(self::FIXTURE, $fixtureDir);

        try {
            $this->runPsalm($fixtureDir, true);

            foreach (['/app/Dependencies/Dependencies.php', '/app/Models/User.php'] as $changed) {
                $this->assertNotFalse(
                    \file_put_contents($fixtureDir . $changed, "\n// incremental change\n", \FILE_APPEND),
                );
            }

            $findings = $this->runPsalm($fixtureDir, true);
            $deadCode = $this->report($findings, self::DEAD_CODE);

            $this->assertStringContainsString(
                'Models\User::privateTeam',
                $deadCode,
                'Expected the incremental run to still consolidate dead code.',
            );
            $this->assertStringNotContainsString(
                'Dependencies\UpdateDriver',
                $deadCode,
                'Expected the queued constructor edge to be replayed after its file changed.',
            );
            $this->assertStringNotContainsString(
                '::team',
                $deadCode,
                'Expected the queued relationship edge to be replayed after the model changed.',
            );
            // The relationship edge is anchored to the plugin file (recordFileReference()), which is
            // never re-analyzed, so its "return value used" half must survive the model file's own
            // invalidation too - not just the method-used half asserted above.
            $this->assertStringNotContainsString(
                'function team(',
                $this->report($findings, self::DEAD_RETURNS),
                'Expected the relationship return value to remain used after the model file changed.',
            );
        } finally {
            $this->removeDirectory($fixtureDir);
        }
    }

    /**
     * Joins each finding's message with its source snippet: method and class findings name their
     * symbol in the message, return-value findings only in the snippet.
     *
     * @param list<array{type: string, message: string, snippet: string}> $findings
     * @param list<string> $types
     */
    private function report(array $findings, array $types): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            if (\in_array($finding['type'], $types, true)) {
                $lines[] = $finding['message'] . ' ' . \trim($finding['snippet']);
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<string> $extraArguments
     * @return list<array{type: string, message: string, snippet: string}>
     */
    private function runPsalm(
        string $fixtureDir,
        bool $useCache,
        string $config = 'psalm.xml',
        array $extraArguments = [],
    ): array {
        $arguments = [
            \PHP_BINARY,
            \dirname(__DIR__, 3) . '/vendor/bin/psalm',
            '-c',
            $config,
            '--threads=1',
            '--no-progress',
            '--output-format=json',
            ...$extraArguments,
        ];
        if (!$useCache) {
            $arguments[] = '--no-cache';
        }

        $process = new Process($arguments, $fixtureDir);
        $process->setTimeout(300);
        $process->run();

        $stdout = $process->getOutput();
        $this->assertSame(
            2,
            $process->getExitCode(),
            "Psalm must report the fixture's intentional dead-code controls.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}",
        );
        $this->assertSame('', \trim($process->getErrorOutput()), 'Psalm emitted an unexpected stderr diagnostic.');

        $decoded = \json_decode($stdout, true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && isset($finding['type'], $finding['message'], $finding['snippet'])) {
                $findings[] = [
                    'type' => (string) $finding['type'],
                    'message' => (string) $finding['message'],
                    'snippet' => (string) $finding['snippet'],
                ];
            }
        }

        return $findings;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        \mkdir($destination, 0777, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                \mkdir($target, 0777, true);
            } else {
                \copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }

        \rmdir($directory);
    }
}
