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
 */
#[CoversClass(IndirectMethodReferenceHandler::class)]
final class IndirectMethodReferencesTest extends TestCase
{
    #[Test]
    public function it_records_only_proven_container_and_relationship_references(): void
    {
        $findings = $this->runPsalmAndCollectUnusedMethodFindings(
            __DIR__ . '/Fixtures/IndirectMethodReferences',
            false,
        );
        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        foreach ([
            'UpdateDriver::__construct',
            'InvokeDependency::__construct',
            'CommandDependency::__construct',
            'ReferenceCommand::handle',
            'DriverController::update',
            'DriverController::traitAction',
            'BaseController::inherited',
            'ConcreteController::show',
            'BaseController::__construct',
            'ConcreteController::__construct',
            'OwnerDependency::__construct',
            'NestedDependency::__construct',
            'InheritedActionDependency::__construct',
            'TraitActionDependency::__construct',
            'User::team',
            'User::ordinaryRelation',
            'BaseUser::baseTeam',
            'RelationTrait::traitTeam',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $joined, "Expected {$marker} to be referenced indirectly.");
        }

        foreach ([
            'UnusedDependency::__construct',
            'HelperDependency::__construct',
            'CommandHelperDependency::__construct',
            'UnionDependencyA::__construct',
            'UnionDependencyB::__construct',
            'ContractImplementation::__construct',
            'DynamicDependency::__construct',
            'ProtectedDependency::__construct',
            'AbstractDependency::__construct',
            'DocblockOnlyDependency::__construct',
            'User::privateTeam',
            'User::ordinaryUnused',
        ] as $marker) {
            $this->assertStringContainsString($marker, $joined, "Expected {$marker} to remain reportable.");
        }
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function runPsalmAndCollectUnusedMethodFindings(string $fixtureDir, bool $useCache): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $arguments = [
            \PHP_BINARY,
            $psalmBinary,
            '-c',
            'psalm.xml',
            '--threads=1',
            '--no-progress',
            '--output-format=json',
        ];
        if (!$useCache) {
            $arguments[] = '--no-cache';
        }

        $process = new Process(
            $arguments,
            $fixtureDir,
        );
        $process->setTimeout(300);
        $process->run();

        $stdout = $process->getOutput();
        $this->assertSame(
            2,
            $process->getExitCode(),
            "Psalm must report the fixture's intentional unused-method controls.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}",
        );
        $this->assertFalse(
            $process->isSuccessful(),
            'The fixture subprocess must not silently pass without exercising dead-code reporting.',
        );
        $this->assertSame('', \trim($process->getErrorOutput()), 'Psalm emitted an unexpected stderr diagnostic.');
        $decoded = \json_decode($stdout, true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}");

        $unusedMethodFindings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'])) {
                continue;
            }

            if ($finding['type'] === 'PossiblyUnusedMethod' || $finding['type'] === 'UnusedMethod') {
                $unusedMethodFindings[] = [
                    'type' => $finding['type'],
                    'message' => (string) $finding['message'],
                ];
            }
        }

        return $unusedMethodFindings;
    }

    #[Test]
    public function cached_runs_replay_references_after_relevant_file_changes(): void
    {
        // Keep the copy under the repository so Psalm's GitInfoCollector does not emit its
        // "not a git repository" warning, which would hide real subprocess failures on stderr.
        $processId = \getmypid();
        if ($processId === false) {
            $processId = 0;
        }

        $fixtureDir = __DIR__ . '/Fixtures/IndirectMethodReferences/.incremental-' . $processId;
        $this->copyDirectory(__DIR__ . '/Fixtures/IndirectMethodReferences', $fixtureDir);

        try {
            $this->runPsalmAndCollectUnusedMethodFindings($fixtureDir, true);

            $dependencies = $fixtureDir . '/app/Dependencies/Dependencies.php';
            $contents = \file_get_contents($dependencies);
            $this->assertIsString($contents);
            $replacements = 0;
            \file_put_contents($dependencies, \str_replace(
                "public function __construct()\n    {\n        \\assert(true);\n    }",
                "public function __construct()\n    {\n        \\assert(true && true);\n    }",
                $contents,
                $replacements,
            ));
            $this->assertSame(2, $replacements);

            $findingsAfterDependencyChange = $this->runPsalmAndCollectUnusedMethodFindings($fixtureDir, true);
            $messages = \implode(
                "\n",
                \array_map(static fn(array $finding): string => $finding['message'], $findingsAfterDependencyChange),
            );
            $this->assertStringNotContainsString('UpdateDriver::__construct', $messages);
            $this->assertStringNotContainsString('NestedDependency::__construct', $messages);

            $user = $fixtureDir . '/app/Models/User.php';
            $contents = \file_get_contents($user);
            $this->assertIsString($contents);
            $replacements = 0;
            \file_put_contents($user, \str_replace(
                'return $this->belongsTo(Team::class);',
                'return $this->belongsTo(Team::class, \'team_id\');',
                $contents,
                $replacements,
            ));
            $this->assertSame(3, $replacements);

            $findingsAfterRelationChange = $this->runPsalmAndCollectUnusedMethodFindings($fixtureDir, true);
            $messages = \implode(
                "\n",
                \array_map(static fn(array $finding): string => $finding['message'], $findingsAfterRelationChange),
            );
            $this->assertStringNotContainsString('User::team', $messages);
            $this->assertStringNotContainsString('User::ordinaryRelation', $messages);
            $this->assertStringContainsString('User::privateTeam', $messages);
            $this->assertStringContainsString('User::ordinaryUnused', $messages);
        } finally {
            $this->removeDirectory($fixtureDir);
        }
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
