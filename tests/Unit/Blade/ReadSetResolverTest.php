<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Blade\ViewDataContract;

/**
 * {@see ReadSetResolver::staleDeclarations()} over contracts built by the real parser, so the read set
 * comes from compiled Blade output rather than a hand-written list. The end-to-end cases live in
 * `ViewContractValidationTest`; these pin what a fixture app cannot reach.
 */
#[CoversClass(ReadSetResolver::class)]
final class ReadSetResolverTest extends TestCase
{
    protected function setUp(): void
    {
        ContractRegistry::reset();
    }

    protected function tearDown(): void
    {
        ContractRegistry::reset();
    }

    /** @param list<string> $includes view names the template hands its whole scope to */
    private function register(string $viewName, string $source, ?ViewDataContract $override = null, array $includes = []): void
    {
        $compiled = (new BladeCompiler(new Filesystem(), \sys_get_temp_dir()))->compileString($source);
        $contract = $override ?? (new ContractParser())->parseDataContract($source, $compiled);

        ContractRegistry::register($viewName, 0, $contract, [$includes, false]);
    }

    #[Test]
    public function a_declaration_nothing_reads_is_stale(): void
    {
        $this->register('stale', "<?php /** @var int \$boost */ ?>\n<p>{{ \$campaign }}</p>\n");

        $this->assertSame(['boost'], ReadSetResolver::staleDeclarations('stale'));
    }

    /** The read set never carries these names, so their absence from it proves nothing. */
    #[Test]
    public function a_declaration_of_a_name_the_read_set_omits_is_never_stale(): void
    {
        $this->register('internal', "<?php /** @var string \$__label */ ?>\n<p>{{ \$__label }}</p>\n");
        $this->register('livewire', "<?php /** @var \\Livewire\\Component \$this */ ?>\n<p>{{ \$this->title }}</p>\n");

        $this->assertSame([], ReadSetResolver::staleDeclarations('internal'));
        $this->assertSame([], ReadSetResolver::staleDeclarations('livewire'));
    }

    #[Test]
    public function a_read_in_a_literal_include_keeps_the_declaration_live_and_a_declaration_there_does_not(): void
    {
        $this->register('host', "<?php /** @var string \$note */ ?>\n@include('partial')\n", includes: ['partial']);
        $this->register('partial', "<?php /** @var string \$note */ ?>\n<p>{{ \$note }}</p>\n");
        $this->register('declaring-host', "<?php /** @var string \$note */ ?>\n@include('declaring-partial')\n", includes: ['declaring-partial']);
        $this->register('declaring-partial', "<?php /** @var string \$note */ ?>\n<p>never read</p>\n");

        $this->assertSame([], ReadSetResolver::staleDeclarations('host'));
        $this->assertSame(['note'], ReadSetResolver::staleDeclarations('declaring-host'));
    }

    /** The gate in the issue: an unreadable `@props` array makes the declared set a lower bound even when the reads are provable. */
    #[Test]
    public function an_unreadable_props_array_declines_even_with_a_provable_read_set(): void
    {
        $contract = (new ContractParser())->parseDataContract("<?php /** @var int \$boost */ ?>\n<p>x</p>\n", '<p>x</p>');
        $this->assertSame(['boost'], \array_keys($contract->vars));
        $this->assertFalse($contract->readsUnknown);

        $this->register('provable', '', $contract);
        $this->register('props', '', new ViewDataContract($contract->vars, true, [], false));

        $this->assertSame(['boost'], ReadSetResolver::staleDeclarations('provable'));
        $this->assertSame([], ReadSetResolver::staleDeclarations('props'));
    }

    /** A dynamic include (flag set) or one naming a template the compile pass never registered. */
    #[Test]
    public function a_chain_with_an_unresolved_include_declines(): void
    {
        ContractRegistry::register('open', 0, (new ContractParser())->parseDataContract("<?php /** @var int \$boost */ ?>\n", ''), [[], true]);
        $this->register('unregistered', "<?php /** @var int \$boost */ ?>\n@include('missing')\n", includes: ['missing']);

        $this->assertSame([], ReadSetResolver::staleDeclarations('open'));
        $this->assertSame([], ReadSetResolver::staleDeclarations('unregistered'));
    }
}
