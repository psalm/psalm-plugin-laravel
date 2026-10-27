<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Stubs;

use Illuminate\Foundation\AliasLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Stubs\AliasStubProvider;
use Psalm\Plugin\RegistrationInterface;
use Tests\Psalm\LaravelPlugin\Unit\Stubs\Fixtures\AliasedFacade;
use Tests\Psalm\LaravelPlugin\Unit\Stubs\Fixtures\AliasedSuit;

#[CoversClass(AliasStubProvider::class)]
final class AliasStubProviderTest extends TestCase
{
    private const EXPECTED_STUB = "<?php\n\nclass Foo extends \\Acme\\FooFacade {}\n";

    private string $dir;

    private ?AliasLoader $originalLoader = null;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/psalm-laravel-alias-' . \uniqid('', true);
        \mkdir($this->dir);

        $property = new \ReflectionProperty(AliasLoader::class, 'instance');
        $original = $property->getValue();
        $this->originalLoader = $original instanceof AliasLoader ? $original : null;

        // The constructor is private; clearing the singleton makes getInstance() build a fresh loader.
        AliasLoader::setInstance(null);
        AliasLoader::getInstance([
            'Foo' => 'Acme\\FooFacade',
            // Namespaced aliases are not valid in the global-namespace stub and must be skipped.
            'Ns\\Bar' => 'Acme\\Bar',
        ]);
    }

    protected function tearDown(): void
    {
        AliasLoader::setInstance($this->originalLoader);
        AliasStubProvider::reset();

        foreach (\array_diff(\scandir($this->dir) ?: [], ['.', '..']) as $entry) {
            $path = $this->dir . '/' . $entry;
            \is_dir($path) ? \rmdir($path) : \unlink($path);
        }

        \rmdir($this->dir);
    }

    #[Test]
    public function it_does_not_rewrite_a_stub_that_already_has_the_same_content(): void
    {
        $location = $this->dir . '/aliases.phpstub';
        \file_put_contents($location, self::EXPECTED_STUB);
        \touch($location, 1_000_000_000);
        \clearstatcache();
        $inode = \fileinode($location);

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        \clearstatcache();
        // A rewrite via temp file + rename would change the inode and bump the mtime.
        $this->assertSame($inode, \fileinode($location));
        $this->assertSame(1_000_000_000, \filemtime($location));
    }

    #[Test]
    public function it_replaces_stale_content_and_leaves_no_temp_file(): void
    {
        $location = $this->dir . '/aliases.phpstub';
        \file_put_contents($location, "<?php\n\nclass Stale extends \\Acme\\Gone {}\n");

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(self::EXPECTED_STUB, \file_get_contents($location));
        $this->assertSame(['aliases.phpstub'], \array_values(\array_diff(\scandir($this->dir) ?: [], ['.', '..'])));
    }

    #[Test]
    public function it_throws_with_the_reason_when_the_stub_cannot_be_written(): void
    {
        // A directory at the stub location makes the rename fail and cannot hold the expected content.
        $location = $this->dir . '/aliases.phpstub';
        \mkdir($location);

        $registration = $this->createMock(RegistrationInterface::class);
        $registration->expects($this->never())->method('addStubFile');

        try {
            AliasStubProvider::register($registration, $location);
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString("Failed to write alias stub file to '{$location}': rename(", $runtimeException->getMessage());
        }

        $this->assertSame(['aliases.phpstub'], \array_values(\array_diff(\scandir($this->dir) ?: [], ['.', '..'])));
    }

    #[Test]
    public function it_writes_an_enum_target_as_class_alias_and_keeps_extends_for_every_other_target(): void
    {
        AliasLoader::getInstance([
            'Suit' => AliasedSuit::class,
            'Loadable' => AliasedFacade::class,
        ]);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        // An enum cannot be a parent class, so `extends` would leave `Suit::Hearts` undefined (#2128).
        $this->assertSame(
            "<?php\n\n"
            . "class Foo extends \\Acme\\FooFacade {}\n"
            . "class_alias(\\Tests\\Psalm\\LaravelPlugin\\Unit\\Stubs\\Fixtures\\AliasedSuit::class, 'Suit');\n"
            . "class Loadable extends \\Tests\\Psalm\\LaravelPlugin\\Unit\\Stubs\\Fixtures\\AliasedFacade {}\n",
            \file_get_contents($location),
        );
        $this->assertSame(['Suit' => AliasedSuit::class], AliasStubProvider::enumAliases());
    }

    #[Test]
    public function it_keeps_the_extends_line_when_loading_the_target_throws(): void
    {
        $autoloader = static function (string $class): void {
            if ($class === 'Acme\\ThrowsOnLoad') {
                throw new \RuntimeException('target cannot be loaded');
            }
        };
        \spl_autoload_register($autoloader);

        try {
            AliasLoader::getInstance(['Broken' => 'Acme\\ThrowsOnLoad']);
            $location = $this->dir . '/aliases.phpstub';

            AliasStubProvider::register($this->registrationExpectingStub($location), $location);
        } finally {
            \spl_autoload_unregister($autoloader);
        }

        $this->assertSame(
            "<?php\n\nclass Foo extends \\Acme\\FooFacade {}\nclass Broken extends \\Acme\\ThrowsOnLoad {}\n",
            \file_get_contents($location),
        );
        $this->assertSame([], AliasStubProvider::enumAliases());
    }

    private function registrationExpectingStub(string $location): RegistrationInterface
    {
        $registration = $this->createMock(RegistrationInterface::class);
        $registration->expects($this->once())->method('addStubFile')->with($location);

        return $registration;
    }
}
