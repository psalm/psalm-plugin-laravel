<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Internal\ClassLineage;

/** Storage built by hand under names that exist nowhere on disk: a pass proves the answer came from storage. */
#[CoversClass(ClassLineage::class)]
final class ClassLineageTest extends TestCase
{
    /** @param class-string $ancestor */
    #[Test]
    #[TestWith(['LineageChild', 'LineageChild', true])]
    #[TestWith(['LineageChild', 'LineageBase', true])]
    #[TestWith(['LineageChildContract', 'LineageContract', true])]
    #[TestWith(['LineageAlias', 'LineageBase', true])]
    #[TestWith(['LineageUnscanned', 'LineageBase', false])]
    public function it_answers_from_storage(string $class, string $ancestor, bool $expected): void
    {
        $provider = new ClassLikeStorageProvider();
        $provider->create('LineageChild')->parent_classes = ['lineagebase' => 'LineageBase'];
        $provider->create('LineageChildContract')->parent_interfaces = ['lineagecontract' => 'LineageContract'];
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $provider;
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        $codebase->classlikes->addClassAlias('LineageChild', 'LineageAlias');

        try {
            $this->assertSame($expected, ClassLineage::isA($codebase, $class, $ancestor));
        } finally {
            foreach (['LineageChild', 'LineageChildContract'] as $name) {
                $provider->remove($name);
            }
        }
    }
}
