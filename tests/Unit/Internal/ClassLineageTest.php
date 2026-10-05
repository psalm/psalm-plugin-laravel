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
    private const CLASSES = [
        'BaseModel', 'UserModel', 'ParentContract', 'ChildContract', 'Status', 'Concern',
        'ExtendsViaAlias', 'ImplementsViaAlias', 'InterfaceViaAlias',
    ];

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
        $status->class_implements = [
            'unitenum' => 'UnitEnum',
            'backedenum' => 'BackedEnum',
            \strtolower(self::NS . 'ParentContract') => self::NS . 'ParentContract',
        ];

        $provider->create(self::NS . 'Concern')->is_trait = true;

        // A declaration naming its parent through an alias: Psalm keys the ancestry maps by the alias.
        $provider->create(self::NS . 'ExtendsViaAlias')->parent_classes = ['aliasbasemodel' => 'AliasBaseModel'];
        $provider->create(self::NS . 'ImplementsViaAlias')->class_implements = ['aliaschildcontract' => 'AliasChildContract'];
        $viaAlias = $provider->create(self::NS . 'InterfaceViaAlias');
        $viaAlias->is_interface = true;
        $viaAlias->parent_interfaces = ['aliaschildcontract' => 'AliasChildContract'];

        // Aliases as Psalm's scanner records a `class_alias()` call; method return types keep the alias.
        $classLikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        $classLikes->addClassAlias(self::NS . 'UserModel', 'AliasUserModel');
        $classLikes->addClassAlias(self::NS . 'BaseModel', 'AliasBaseModel');
        $classLikes->addClassAlias(self::NS . 'ChildContract', 'AliasChildContract');

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
        yield 'identity is case-insensitive' => ['~usermodel', '~USERMODEL', true];
        yield 'parent class' => ['~UserModel', '~BaseModel', true];
        yield 'parent class, case-insensitive' => ['~USERMODEL', '~basemodel', true];
        yield 'implemented interface' => ['~UserModel', '~ChildContract', true];
        yield 'not a descendant' => ['~BaseModel', '~UserModel', false];
        yield 'interface extends interface' => ['~ChildContract', '~ParentContract', true];
        yield 'interface does not extend its child' => ['~ParentContract', '~ChildContract', false];
        yield 'enum is UnitEnum' => ['~Status', 'UnitEnum', true];
        yield 'backed enum is BackedEnum' => ['~Status', 'BackedEnum', true];
        yield 'enum implements interface' => ['~Status', '~ParentContract', true];
        yield 'trait is nothing else' => ['~Concern', '~BaseModel', false];
        yield 'absent storage' => ['~Unscanned', '~BaseModel', false];
        yield 'leading backslash on the class' => ['\\~UserModel', '~BaseModel', true];
        yield 'leading backslash on the ancestor' => ['~Status', '\\BackedEnum', true];
        yield 'leading backslash on both, identity' => ['\\~UserModel', '\\~UserModel', true];
        yield 'aliased class' => ['AliasUserModel', '~BaseModel', true];
        yield 'aliased class, case-insensitive and backslashed' => ['\\aliasusermodel', '~BaseModel', true];
        yield 'aliased ancestor' => ['~UserModel', 'AliasBaseModel', true];
        yield 'alias identity' => ['AliasUserModel', '~UserModel', true];
        yield 'aliased class, not a descendant' => ['AliasBaseModel', '~UserModel', false];
        yield 'parent class named through an alias' => ['~ExtendsViaAlias', '~BaseModel', true];
        yield 'interface implemented through an alias' => ['~ImplementsViaAlias', '~ChildContract', true];
        yield 'interface extended through an alias' => ['~InterfaceViaAlias', '~ChildContract', true];
        yield 'interface implemented through an alias, unrelated ancestor' => ['~ImplementsViaAlias', '~BaseModel', false];
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
