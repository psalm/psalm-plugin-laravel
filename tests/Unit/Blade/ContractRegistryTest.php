<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\ViewDataContract;

#[CoversClass(ContractRegistry::class)]
final class ContractRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ContractRegistry::reset();
    }

    protected function tearDown(): void
    {
        ContractRegistry::reset();
    }

    private function contractDeclaring(string $type): ViewDataContract
    {
        return new ViewDataContract(['name' => new ContractVar('name', $type, 1, false)], false);
    }

    #[Test]
    public function an_unregistered_view_name_has_no_contract(): void
    {
        $this->assertNull(ContractRegistry::contractFor('emails.welcome'));
    }

    #[Test]
    public function the_earliest_view_root_wins_whichever_order_the_templates_arrive_in(): void
    {
        // Templates are walked in path order, not view-root order, so the later root can register
        // first. FileViewFinder renders the earliest root's file, and this has to agree with it.
        ContractRegistry::register('profile', 2, $this->contractDeclaring('int'));
        ContractRegistry::register('profile', 0, $this->contractDeclaring('string'));
        ContractRegistry::register('profile', 1, $this->contractDeclaring('float'));

        $contract = ContractRegistry::contractFor('profile');

        $this->assertNotNull($contract);
        $this->assertSame('string', $contract->vars['name']->typeString);
    }

    #[Test]
    public function reset_clears_a_previous_invocations_contracts(): void
    {
        ContractRegistry::register('profile', 0, $this->contractDeclaring('string'));
        ContractRegistry::reset();

        $this->assertNull(ContractRegistry::contractFor('profile'));
    }
}
