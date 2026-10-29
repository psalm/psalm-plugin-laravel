<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ShadowPropertyAccesses;

#[CoversClass(ShadowPropertyAccesses::class)]
final class ShadowPropertyAccessesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function resolvedReceivers(): iterable
    {
        yield 'variable' => ['$m->p = 1;'];
        yield 'this' => ['$this->p = 1;'];
        yield 'property chain' => ['$a->b->p = 1;'];
        yield 'static property' => ['Foo::$s->p = 1;'];
        yield 'static property chain' => ['Foo::$s->b->p = 1;'];
        yield 'read and write through variables' => ['$m->p = 1; echo $a->p;'];
        yield 'other names are ignored' => ['$m->p = 1; Foo::make()->other = 2; $x[0]->other = 3;'];
        yield 'method call named like the property' => ['$m->p = 1; Foo::make()->p();'];
    }

    #[Test]
    #[DataProvider('resolvedReceivers')]
    public function every_fetch_having_a_var_id_is_accepted(string $body): void
    {
        $this->assertTrue(ShadowPropertyAccesses::allHaveVarId("<?php\n" . $body . "\n", 'p'));
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedReceivers(): iterable
    {
        yield 'static call result' => ['Foo::make()->p = 1;'];
        yield 'function call result' => ['make()->p = 1;'];
        yield 'method call result' => ['$m->make()->p = 1;'];
        yield 'array offset' => ['$a[0]->p = 1;'];
        yield 'property of an array offset' => ['$a[0]->b->p = 1;'];
        yield 'nullsafe fetch' => ['echo $m?->p;'];
        yield 'nullsafe receiver' => ['$m?->b->p = 1;'];
        yield 'variable variable receiver' => ['$$n->p = 1;'];
        yield 'one unresolved among resolved' => ['$m->p = 1; Foo::make()->p = 2;'];
        yield 'new expression receiver' => ['(new Foo())->p = 1;'];
    }

    #[Test]
    #[DataProvider('unresolvedReceivers')]
    public function one_fetch_without_a_var_id_is_not_proof(string $body): void
    {
        $this->assertFalse(ShadowPropertyAccesses::allHaveVarId("<?php\n" . $body . "\n", 'p'));
    }

    /** @return iterable<string, array{string}> */
    public static function dynamicNames(): iterable
    {
        yield 'variable name' => ['$m->p = 1; $m->{$n} = 2;'];
        yield 'literal name' => ['$m->p = 1; $m->{\'p\'} = 2;'];
        yield 'dynamic name on an unrelated receiver' => ['$m->p = 1; Foo::make()->{$n} = 2;'];
    }

    #[Test]
    #[DataProvider('dynamicNames')]
    public function a_fetch_with_a_non_identifier_name_is_not_proof(string $body): void
    {
        $this->assertFalse(ShadowPropertyAccesses::allHaveVarId("<?php\n" . $body . "\n", 'p'));
    }

    #[Test]
    public function no_fetch_of_the_name_is_not_proof(): void
    {
        $this->assertFalse(ShadowPropertyAccesses::allHaveVarId("<?php\n\$m->other = 1;\n", 'p'));
    }

    #[Test]
    public function an_unparseable_shadow_is_not_proof(): void
    {
        $this->assertFalse(ShadowPropertyAccesses::allHaveVarId("<?php\n\$m->p = ;\n", 'p'));
    }

    #[Test]
    public function property_names_are_case_sensitive(): void
    {
        $this->assertFalse(ShadowPropertyAccesses::allHaveVarId("<?php\n\$m->P = 1;\n", 'p'));
    }
}
