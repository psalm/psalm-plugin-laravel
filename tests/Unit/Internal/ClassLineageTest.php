<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Internal\ClassLineage;

/**
 * Storage shapes as Psalm's Populator leaves them (ancestry flattened into lowercase-keyed maps), built
 * by hand under names that exist nowhere on disk: a passing check proves the answer came from storage.
 * In the rows below `~` stands for the fixture namespace.
 */
#[CoversClass(ClassLineage::class)]
final class ClassLineageTest extends TestCase
{
    private const NS = 'ClassLineageFixture\\';

    /** @var list<string> */
    private const CLASSES = ['BaseModel', 'UserModel', 'ParentContract', 'ChildContract', 'Status', 'ExtendsViaAlias'];

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        $provider = new ClassLikeStorageProvider();

        $provider->create(self::NS . 'BaseModel');

        $user = $provider->create(self::NS . 'UserModel');
        $user->parent_classes = [\strtolower(self::NS . 'BaseModel') => self::NS . 'BaseModel'];
        $user->class_implements = [\strtolower(self::NS . 'ChildContract') => self::NS . 'ChildContract'];

        $provider->create(self::NS . 'ParentContract')->is_interface = true;

        $child = $provider->create(self::NS . 'ChildContract');
        $child->is_interface = true;
        $child->parent_interfaces = [\strtolower(self::NS . 'ParentContract') => self::NS . 'ParentContract'];

        $status = $provider->create(self::NS . 'Status');
        $status->is_enum = true;
        $status->class_implements = ['backedenum' => 'BackedEnum'];

        // A declaration naming its parent through an alias: Psalm keys the ancestry maps by the alias.
        $provider->create(self::NS . 'ExtendsViaAlias')->parent_classes = ['aliasbasemodel' => 'AliasBaseModel'];

        // Aliases as Psalm's scanner records a `class_alias()` call; method return types keep the alias.
        $classLikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        $classLikes->addClassAlias(self::NS . 'UserModel', 'AliasUserModel');
        $classLikes->addClassAlias(self::NS . 'BaseModel', 'AliasBaseModel');

        $this->codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $this->codebase->classlike_storage_provider = $provider;
        $this->codebase->classlikes = $classLikes;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $provider = new ClassLikeStorageProvider();
        foreach (self::CLASSES as $class) {
            $provider->remove(self::NS . $class);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function lineage(): iterable
    {
        yield 'class is its own ancestor' => ['~UserModel', '~UserModel', true];
        yield 'parent class' => ['~UserModel', '~BaseModel', true];
        yield 'implemented interface' => ['~UserModel', '~ChildContract', true];
        yield 'interface extends interface' => ['~ChildContract', '~ParentContract', true];
        yield 'backed enum is BackedEnum' => ['~Status', 'BackedEnum', true];
        yield 'not a descendant' => ['~BaseModel', '~UserModel', false];
        yield 'absent storage' => ['~Unscanned', '~BaseModel', false];
        yield 'leading backslash' => ['\\~UserModel', '~BaseModel', true];
        yield 'aliased class' => ['AliasUserModel', '~BaseModel', true];
        yield 'aliased ancestor' => ['~UserModel', 'AliasBaseModel', true];
        yield 'parent class named through an alias' => ['~ExtendsViaAlias', '~BaseModel', true];
    }

    #[Test]
    #[DataProvider('lineage')]
    public function it_answers_from_storage(string $class, string $ancestor, bool $expected): void
    {
        $this->assertSame($expected, ClassLineage::isA($this->codebase, $this->qualify($class), $this->qualify($ancestor)));
    }

    #[Test]
    public function it_returns_storage_under_the_canonical_name(): void
    {
        $this->assertSame(self::NS . 'UserModel', ClassLineage::storage($this->codebase, '\\AliasUserModel')?->name);
        $this->assertNull(ClassLineage::storage($this->codebase, self::NS . 'Unscanned'));
    }

    /** @return class-string */
    private function qualify(string $name): string
    {
        /** @var class-string */
        return \str_replace('~', self::NS, $name);
    }
}
