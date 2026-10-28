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

    /**
     * The earliest root wins even when what it declares is nothing. A template with no declarations
     * still claims its name, so a same-named template in a later root cannot have its `@var`
     * comments checked against callers Laravel resolves to the earlier file.
     */
    #[Test]
    public function an_empty_contract_in_an_earlier_root_still_shadows_a_later_declaring_one(): void
    {
        ContractRegistry::register('dup', 0, new ViewDataContract([], false));
        ContractRegistry::register('dup', 1, self::contractDeclaring('string'));

        $contract = ContractRegistry::contractFor('dup');

        $this->assertNotNull($contract);
        $this->assertSame([], $contract->vars);
    }

    /** `[]` and `null` must stay apart: the first lets the call site be judged in full, the second skips the view. */
    #[Test]
    public function composed_keys_distinguish_no_composer_from_an_unresolved_one(): void
    {
        ContractRegistry::registerComposerSources(['composed' => [['App\\Composer', 'compose']], 'opaque' => null]);
        $this->assertSame([], ContractRegistry::composedKeys('plain'));
        $this->assertNull(ContractRegistry::composedKeys('composed'), 'sources the resolver never reached');

        ContractRegistry::registerComposedKeys(['composed' => ['title' => true], 'opaque' => null]);
        $this->assertSame(['title' => true], ContractRegistry::composedKeys('composed'));
        $this->assertNull(ContractRegistry::composedKeys('opaque'));

        ContractRegistry::reset();
        $this->assertSame([], ContractRegistry::composedKeys('composed'));
        $this->assertSame([], ContractRegistry::composerSources());
    }
}
