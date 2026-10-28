<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Stubs;

use Illuminate\Foundation\AliasLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Stubs\AliasStubProvider;
use Psalm\Plugin\RegistrationInterface;

#[CoversClass(AliasStubProvider::class)]
final class AliasStubProviderTest extends TestCase
{
    private const EXPECTED_FOO_STUB = "<?php\n\nclass_alias(\\Acme\\FooFacade::class, 'Foo');\n";

    private const EXPECTED_STUB = self::EXPECTED_FOO_STUB . "class_alias(\\Acme\\Bar::class, 'Ns\\\\Bar');\n";

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
    public function it_writes_every_target_kind_as_class_alias_including_an_unloadable_one(): void
    {
        // The provider names a target without loading it, so a class, an enum, an interface and a
        // class that does not exist all take the same line.
        $this->useAliases([
            'Klass' => \stdClass::class,
            'Suit' => \DateTimeInterface::class,
            'Gone' => 'Acme\\DoesNotExist',
        ]);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(
            "<?php\n\n"
            . "class_alias(\\stdClass::class, 'Klass');\n"
            . "class_alias(\\DateTimeInterface::class, 'Suit');\n"
            . "class_alias(\\Acme\\DoesNotExist::class, 'Gone');\n",
            \file_get_contents($location),
        );
    }

    #[Test]
    public function it_writes_a_namespaced_alias(): void
    {
        $this->useAliases(['Ns\\Bar' => 'Acme\\Bar']);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame("<?php\n\nclass_alias(\\Acme\\Bar::class, 'Ns\\\\Bar');\n", \file_get_contents($location));
    }

    #[Test]
    public function it_normalizes_leading_backslashes_on_alias_and_target(): void
    {
        $this->useAliases(['\\Foo' => '\\Acme\\FooFacade']);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(self::EXPECTED_FOO_STUB, \file_get_contents($location));
        $this->assertSame(['Foo' => 'Acme\\FooFacade'], AliasStubProvider::aliases());
    }

    #[Test]
    public function it_quotes_the_alias_as_a_php_string(): void
    {
        $this->useAliases(["It's" => \stdClass::class]);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        // TOKEN_PARSE throws a ParseError when the line is not valid PHP.
        $strings = [];
        foreach (\token_get_all((string) \file_get_contents($location), \TOKEN_PARSE) as $token) {
            if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = $token[1];
            }
        }

        $this->assertSame(["'It\\'s'"], $strings);
    }

    #[Test]
    public function it_skips_a_self_alias_case_insensitively(): void
    {
        $this->useAliases(['Introspect' => 'Introspect', 'Same' => '\\same', 'Foo' => 'Acme\\FooFacade']);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(self::EXPECTED_FOO_STUB, \file_get_contents($location));
        $this->assertSame(['Foo' => 'Acme\\FooFacade'], AliasStubProvider::aliases());
    }

    #[Test]
    public function it_skips_alias_cycles_and_keeps_unrelated_aliases(): void
    {
        // Psalm's getUnAliasedName() has no cycle guard: A => B, B => A would recurse forever.
        $this->useAliases([
            'CycleA' => 'cyclea',
            'Ping' => 'Pong',
            'Pong' => '\\Ping',
            'Foo' => 'Acme\\FooFacade',
        ]);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(self::EXPECTED_FOO_STUB, \file_get_contents($location));
        $this->assertSame(['Foo' => 'Acme\\FooFacade'], AliasStubProvider::aliases());
    }

    #[Test]
    public function it_keeps_an_alias_that_only_leads_into_a_cycle_it_is_not_part_of(): void
    {
        $this->useAliases(['Entry' => 'Ping', 'Ping' => 'Pong', 'Pong' => 'Ping']);
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(['Entry' => 'Ping'], AliasStubProvider::aliases());
    }

    #[Test]
    public function it_never_autoloads_a_target(): void
    {
        $requested = [];
        $autoloader = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        \spl_autoload_register($autoloader);

        try {
            $this->useAliases(['Broken' => 'Acme\\ThrowsOnLoad', 'Foo' => 'Acme\\FooFacade']);
            $location = $this->dir . '/aliases.phpstub';

            AliasStubProvider::register($this->registrationExpectingStub($location), $location);
        } finally {
            \spl_autoload_unregister($autoloader);
        }

        $this->assertSame([], $requested);
    }

    #[Test]
    public function it_exposes_exactly_the_written_aliases_and_forgets_them_on_reset(): void
    {
        $location = $this->dir . '/aliases.phpstub';

        AliasStubProvider::register($this->registrationExpectingStub($location), $location);

        $this->assertSame(['Foo' => 'Acme\\FooFacade', 'Ns\\Bar' => 'Acme\\Bar'], AliasStubProvider::aliases());

        AliasStubProvider::reset();

        $this->assertSame([], AliasStubProvider::aliases());
    }

    /**
     * @param array<string, string> $aliases replaces the whole AliasLoader map, unlike getInstance($aliases), which merges
     */
    private function useAliases(array $aliases): void
    {
        AliasLoader::getInstance()->setAliases($aliases);
    }

    private function registrationExpectingStub(string $location): RegistrationInterface
    {
        $registration = $this->createMock(RegistrationInterface::class);
        $registration->expects($this->once())->method('addStubFile')->with($location);

        return $registration;
    }
}
