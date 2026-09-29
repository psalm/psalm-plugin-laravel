<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Pest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Pest\PestTestCaseResolver;

#[CoversClass(PestTestCaseResolver::class)]
final class PestTestCaseResolverTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        PestTestCaseResolver::reset();
        $this->root = \sys_get_temp_dir() . '/pest-resolver-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/tests/Feature', 0o777, true);
        \mkdir($this->root . '/tests/Unit', 0o777, true);
        $this->root = (string) \realpath($this->root);
    }

    #[\Override]
    protected function tearDown(): void
    {
        PestTestCaseResolver::reset();
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
    }

    #[Test]
    public function directory_mapping_wins_and_traits_are_ignored(): void
    {
        $this->writePest("uses(Tests\\TestCase::class, Tests\\Seeds::class)->in('Feature');");

        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function unmapped_file_with_a_readable_config_gets_the_phpunit_default(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");

        $this->assertSame(PestTestCaseResolver::DEFAULT_TEST_CASE, $this->resolve('Unit/MathTest.php'));
    }

    #[Test]
    public function trait_only_mapping_keeps_the_phpunit_default(): void
    {
        $this->writePest("uses(Tests\\Seeds::class)->in('Feature');");

        $this->assertSame(PestTestCaseResolver::DEFAULT_TEST_CASE, $this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function missing_config_declines_unless_the_file_names_its_own_class(): void
    {
        $this->assertNull($this->resolve('Feature/UserTest.php'));

        PestTestCaseResolver::reset();
        $this->assertSame('Tests\Other', $this->resolve('Feature/OtherTest.php', 'uses(Tests\\Other::class);'));
    }

    #[Test]
    public function unreadable_config_declines_unless_the_file_names_its_own_class(): void
    {
        $this->writePest('uses($case)->in("Feature");');

        $this->assertNull($this->resolve('Feature/UserTest.php'));
        $this->assertSame('Tests\Other', $this->resolve('Feature/OtherTest.php', 'pest()->extend(Tests\\Other::class);'));
    }

    #[Test]
    public function conflicting_classes_decline(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");

        $this->assertNull($this->resolve('Feature/UserTest.php', 'uses(Tests\\Other::class);'));
    }

    #[Test]
    public function the_same_class_twice_is_not_a_conflict(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");

        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php', 'uses(\\Tests\\TestCase::class);'));
    }

    #[Test]
    public function unknown_class_declines(): void
    {
        $this->writePest("uses(Tests\\Missing::class)->in('Feature');");

        $this->assertNull($this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function unreadable_test_file_declines(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");

        $this->assertNull($this->resolve('Feature/UserTest.php', 'uses($case);'));
    }

    #[Test]
    public function answers_are_memoized_until_reset(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");
        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php'));

        $this->writePest("uses(Tests\\Other::class)->in('Feature');");
        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php'));

        PestTestCaseResolver::reset();
        $this->assertSame('Tests\Other', $this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function file_outside_the_tests_directory_declines_instead_of_the_default(): void
    {
        $this->writePest("uses(Tests\\TestCase::class)->in('Feature');");
        \mkdir($this->root . '/packages/foo/tests', 0o777, true);

        $this->assertNull($this->resolve('../packages/foo/tests/PkgTest.php'));
    }

    #[Test]
    public function boot_files_are_config_sources_too(): void
    {
        $this->writePest('');
        \file_put_contents($this->root . '/tests/Helpers.php', "<?php\nuses(Tests\\TestCase::class)->in('Feature');");
        \mkdir($this->root . '/tests/Expectations/Nested', 0o777, true);
        \file_put_contents($this->root . '/tests/Expectations/Nested/Api.php', "<?php\npest()->extend(Tests\\Other::class)->in(__DIR__ . '/../../Unit');");

        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php'));
        $this->assertSame('Tests\Other', $this->resolve('Unit/MathTest.php'));
    }

    #[Test]
    public function dataset_files_are_config_sources_too(): void
    {
        // BootFiles::bootDatasets(): any Datasets.php, and any file under a Datasets/ directory.
        $this->writePest('');
        \file_put_contents($this->root . '/tests/Datasets.php', "<?php\nuses(Tests\\TestCase::class)->in('Feature');");
        \mkdir($this->root . '/tests/Unit/Datasets', 0o777, true);
        \file_put_contents($this->root . '/tests/Unit/Datasets/Numbers.php', "<?php\nuses(Tests\\Other::class)->in(__DIR__ . '/..');");

        $this->assertSame('Tests\TestCase', $this->resolve('Feature/UserTest.php'));
        $this->assertSame('Tests\Other', $this->resolve('Unit/MathTest.php'));
    }

    #[Test]
    public function unreadable_nested_dataset_file_declines(): void
    {
        $this->writePest('');
        \mkdir($this->root . '/tests/Feature/Api', 0o777, true);
        \file_put_contents($this->root . '/tests/Feature/Api/Datasets.php', '<?php uses($case)->in("..");');

        $this->assertNull($this->resolve('Unit/MathTest.php'));
    }

    #[Test]
    public function datasets_in_hidden_directories_are_skipped_like_pest_does(): void
    {
        $this->writePest('');
        \mkdir($this->root . '/tests/.archive', 0o777, true);
        \file_put_contents($this->root . '/tests/.archive/Datasets.php', "<?php\nuses(Tests\\Other::class)->in(__DIR__ . '/../Feature');");

        $this->assertSame(PestTestCaseResolver::DEFAULT_TEST_CASE, $this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function symlinked_directory_in_the_test_tree_declines(): void
    {
        $this->writePest('');
        \mkdir($this->root . '/shared/Datasets', 0o777, true);
        \file_put_contents($this->root . '/shared/Datasets/Cases.php', "<?php\nuses(Tests\\Other::class)->in(__DIR__ . '/../../tests/Feature');");
        \symlink($this->root . '/shared', $this->root . '/tests/Shared');

        $this->assertNull($this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function symlinked_boot_file_declines(): void
    {
        \mkdir($this->root . '/support', 0o777, true);
        \file_put_contents($this->root . '/support/PestConfig.php', "<?php\npest()->extend(Tests\\TestCase::class);");
        \symlink($this->root . '/support/PestConfig.php', $this->root . '/tests/Pest.php');

        $this->assertNull($this->resolve('Feature/UserTest.php'));
    }

    #[Test]
    public function unreadable_boot_file_declines(): void
    {
        $this->writePest('');
        \mkdir($this->root . '/tests/Helpers', 0o777, true);
        \file_put_contents($this->root . '/tests/Helpers/Auth.php', '<?php if (true) { uses(Tests\\Other::class)->in("Unit"); }');

        $this->assertNull($this->resolve('Unit/MathTest.php'));
    }

    private function writePest(string $source): void
    {
        \file_put_contents($this->root . '/tests/Pest.php', "<?php\n" . $source);
    }

    private function resolve(string $relativeTestFile, string $testSource = ''): ?string
    {
        $testFile = $this->root . '/tests/' . $relativeTestFile;
        \file_put_contents($testFile, "<?php\n" . $testSource);

        return PestTestCaseResolver::resolve(
            $this->root . '/tests',
            $testFile,
            "<?php\n" . $testSource,
            static fn(string $class): ?bool => match ($class) {
                'Tests\TestCase', 'Tests\Other' => true,
                'Tests\Seeds' => false,
                default => null,
            },
        );
    }
}
