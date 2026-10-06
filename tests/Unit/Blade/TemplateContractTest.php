<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\TemplateContract;

#[CoversClass(TemplateContract::class)]
final class TemplateContractTest extends TestCase
{
    #[Test]
    public function contract_vars_maps_names_to_type_strings(): void
    {
        $contract = new TemplateContract(
            ['user' => new ContractVar('user', '\App\Models\User', 1, false)],
            [],
            [],
            false,
        );

        $this->assertSame(['user' => '\App\Models\User'], $contract->contractVars());
    }

    #[Test]
    public function contract_vars_is_empty_for_an_empty_contract(): void
    {
        $contract = new TemplateContract([], [], [], false);

        $this->assertSame([], $contract->contractVars());
    }
}
