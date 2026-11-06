<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;

/**
 * Storage built by hand under names that exist nowhere on disk. A subclass scanned before a
 * `class_alias()` declaration keeps the alias as its `parent_classes` key, which a phpt cannot
 * reproduce: Psalm crashes analysing such a class, and with the alias declared first it records
 * the real name.
 */
#[CoversClass(RelationResolver::class)]
final class RelationResolverDescendantTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        RelationResolver::reset();
    }

    #[Test]
    public function it_finds_a_descendant_that_extends_the_base_through_a_class_alias(): void
    {
        $provider = new ClassLikeStorageProvider();
        $child = $provider->create('RelAliasChild');
        $child->user_defined = true;
        $child->parent_classes = ['relaliasname' => 'RelAliasName'];

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $provider;
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        $codebase->classlikes->addClassAlias('RelAliasBase', 'RelAliasName');

        // The method lookup itself is Psalm's; seed its cache so only the descendant search is under test.
        (new \ReflectionProperty(RelationResolver::class, 'methodExistsCache'))
            ->setValue(null, ['RelAliasChild::aliasedonly' => true, 'RelAliasChild::typo' => false]);

        try {
            $this->assertTrue(RelationResolver::relationMethodExistsOnDescendant($codebase, 'RelAliasBase', 'aliasedOnly'));
            $this->assertFalse(RelationResolver::relationMethodExistsOnDescendant($codebase, 'RelAliasBase', 'typo'));
        } finally {
            $provider->remove('RelAliasChild');
        }
    }
}
