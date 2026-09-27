<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\LaravelAiIntegration;

#[CoversClass(LaravelAiIntegration::class)]
final class LaravelAiIntegrationTest extends TestCase
{
    #[Test]
    public function every_pre_v1_stub_has_a_v1_counterpart(): void
    {
        $preV1Files = $this->stubFiles($this->variantDirectory('pre-1.0'));
        $v1Files = $this->stubFiles($this->variantDirectory('v1'));
        $missingFromV1 = \array_values(\array_diff($preV1Files, $v1Files));

        $this->assertSame(
            [],
            $missingFromV1,
            'Every pre-1.0 stub must have a v1 counterpart so type and taint coverage cannot silently disappear for laravel/ai 1.x users.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function variantDirectories(): iterable
    {
        yield 'pre-1.0' => ['pre-1.0'];
        yield 'v1' => ['v1'];
    }

    #[Test]
    #[DataProvider('variantDirectories')]
    public function shared_stubs_do_not_redeclare_a_variant_declaration(string $variant): void
    {
        $sharedDeclarations = $this->declarationFiles($this->variantDirectory('shared'));
        $variantDeclarations = $this->declarationFiles($this->variantDirectory($variant));
        $duplicateNames = \array_values(\array_intersect(\array_keys($sharedDeclarations), \array_keys($variantDeclarations)));

        $duplicates = [];
        foreach ($duplicateNames as $name) {
            $duplicates[] = \sprintf(
                '%s: shared/%s and %s/%s',
                $name,
                $sharedDeclarations[$name],
                $variant,
                $variantDeclarations[$name],
            );
        }

        $this->assertSame(
            [],
            $duplicates,
            'Shared stubs and a major-specific variant must not declare the same symbol; otherwise coverage depends on stub load order.',
        );
    }

    #[Test]
    public function declaration_parser_finds_symbols_in_each_stub_tree(): void
    {
        $this->assertSame(
            'helpers.phpstub',
            $this->declarationFiles($this->variantDirectory('shared'))['Laravel\\Ai\\agent'] ?? null,
        );
        $this->assertSame(
            'Promptable.phpstub',
            $this->declarationFiles($this->variantDirectory('pre-1.0'))['Laravel\\Ai\\Promptable'] ?? null,
        );
        $this->assertSame(
            'Promptable.phpstub',
            $this->declarationFiles($this->variantDirectory('v1'))['Laravel\\Ai\\Promptable'] ?? null,
        );
    }

    #[Test]
    public function the_installed_laravel_ai_capabilities_select_an_existing_stub_variant(): void
    {
        if (!InstalledVersions::isInstalled(LaravelAiIntegration::PACKAGE)) {
            $this->markTestSkipped('laravel/ai is not installed.');
        }

        $variant = LaravelAiIntegration::stubVariantDirectory();

        $this->assertDirectoryExists($this->variantDirectory($variant));

        if (\class_exists(\Laravel\Ai\PendingStep::class)) {
            $this->assertSame('v1', $variant);

            return;
        }

        $this->assertSame('pre-1.0', $variant);
    }

    private function variantDirectory(string $variant): string
    {
        return \dirname(__DIR__, 3) . '/stubs/integrations/laravel-ai/' . $variant;
    }

    /** @return list<string> */
    private function stubFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'phpstub') {
                continue;
            }

            $files[] = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($file->getPathname(), \strlen($directory) + 1));
        }

        \sort($files);

        return $files;
    }

    /**
     * @return array<string, string> Fully-qualified declaration name => relative stub file
     */
    private function declarationFiles(string $directory): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $declarations = [];

        foreach ($this->stubFiles($directory) as $relativePath) {
            $path = $directory . '/' . $relativePath;
            $source = \file_get_contents($path);

            if (!\is_string($source)) {
                throw new \RuntimeException("Unable to read {$path}.");
            }

            $statements = $parser->parse($source);
            if (!\is_array($statements)) {
                throw new \RuntimeException("Unable to parse {$path}.");
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $statements = $traverser->traverse($statements);

            $declarationNodes = [
                ...$finder->findInstanceOf($statements, Node\Stmt\ClassLike::class),
                ...$finder->findInstanceOf($statements, Node\Stmt\Function_::class),
            ];

            foreach ($declarationNodes as $declaration) {
                $name = $declaration->namespacedName;
                if (!$name instanceof Node\Name) {
                    continue;
                }

                $declarations[$name->toString()] = $relativePath;
            }
        }

        return $declarations;
    }
}
