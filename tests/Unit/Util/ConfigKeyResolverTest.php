<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\ConfigKeyResolver;
use Psalm\Type;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

#[CoversClass(ConfigKeyResolver::class)]
final class ConfigKeyResolverTest extends TestCase
{
    // `TLiteralString::make()` and friends read `Config::getInstance()`.
    // Tests that exercise generalizeDefault need the singleton planted.
    use InitializesPsalmConfigSingleton;

    protected function tearDown(): void
    {
        ConfigKeyResolver::reset();
    }

    #[Test]
    public function resolveCallReturnType_returns_null_when_key_absent_and_no_default(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        // Missing key + no-default sentinel (Type::getNull()) → generalized null
        $type = $resolver->resolveCallReturnType('app.missing', Type::getNull());
        $this->assertTrue($type->isNull());
    }

    #[Test]
    public function resolveCallReturnType_returns_generalized_string_for_string_value(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        $type = $resolver->resolveCallReturnType('app.name', Type::getNull());
        // Generalized — not 'Laravel' literal.
        $this->assertSame('string', $type->getId());
    }

    #[Test]
    public function resolveCallReturnType_keeps_null_when_value_is_literally_null_and_no_default(): void
    {
        $repo = $this->fakeRepository(['app.providers' => null]);
        $resolver = new ConfigKeyResolver($repo);

        // Reflected value is null; default sentinel is null too → result is null.
        $type = $resolver->resolveCallReturnType('app.providers', Type::getNull());
        $this->assertTrue($type->isNull());
    }

    #[Test]
    public function resolveCallReturnType_returns_mixed_when_repository_throws(): void
    {
        $repo = new class implements ConfigRepository {
            #[\Override]
            public function has($key): bool
            {
                throw new \RuntimeException('config exploded');
            }

            #[\Override]
            public function get($key, $default = null): void
            {
                throw new \RuntimeException('config exploded');
            }

            #[\Override]
            public function all(): array
            {
                return [];
            }

            #[\Override]
            public function set($key, $value = null): void {}

            #[\Override]
            public function prepend($key, $value): void {}

            #[\Override]
            public function push($key, $value): void {}
        };

        $resolver = new ConfigKeyResolver($repo);
        $type = $resolver->resolveCallReturnType('any.key', Type::getNull());

        $this->assertTrue($type->isMixed());
    }

    #[Test]
    public function resolveCallReturnType_returns_mixed_when_default_is_mixed(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        // Key absent + default of unknown type → caller passes Type::getMixed()
        // (readDefaultTypeAt sentinel). Result must stay mixed, not collapse.
        $type = $resolver->resolveCallReturnType('app.missing', Type::getMixed());
        $this->assertTrue($type->isMixed());
    }

    #[Test]
    public function cache_hit_avoids_second_repository_call(): void
    {
        $callCount = 0;
        $repo = new class ($callCount) implements ConfigRepository {
            public function __construct(private int &$callCount) {}

            #[\Override]
            public function has($key): bool
            {
                $this->callCount++;
                return true;
            }

            #[\Override]
            public function get($key, $default = null): string
            {
                return 'Laravel';
            }

            #[\Override]
            public function all(): array
            {
                return [];
            }

            #[\Override]
            public function set($key, $value = null): void {}

            #[\Override]
            public function prepend($key, $value): void {}

            #[\Override]
            public function push($key, $value): void {}
        };

        $resolver = new ConfigKeyResolver($repo);
        $resolver->resolveCallReturnType('app.name', Type::getNull());
        $resolver->resolveCallReturnType('app.name', Type::getNull());
        $resolver->resolveCallReturnType('app.name', Type::getString());

        $this->assertSame(1, $callCount, 'Cache must coalesce repeated lookups to a single has() call.');
    }

    #[Test]
    public function resolveCallReturnType_returns_reflected_when_value_not_null(): void
    {
        $repo = $this->fakeRepository(['app.debug' => true]);
        $resolver = new ConfigKeyResolver($repo);

        $type = $resolver->resolveCallReturnType('app.debug', Type::getString());
        // Reflected wins over default — `bool`, not `bool|string`
        $this->assertSame('bool', $type->getId());
    }

    #[Test]
    public function resolveCallReturnType_returns_generalized_default_when_key_absent(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        // Literal 'fallback' default → generalize to string (not literal).
        $type = $resolver->resolveCallReturnType(
            'app.missing',
            new \Psalm\Type\Union([\Psalm\Type\Atomic\TLiteralString::make('fallback')]),
        );

        $this->assertSame('string', $type->getId());
    }

    #[Test]
    public function resolveCallReturnType_returns_null_when_value_is_null_ignoring_default(): void
    {
        $repo = $this->fakeRepository(['app.providers' => null]);
        $resolver = new ConfigKeyResolver($repo);

        $type = $resolver->resolveCallReturnType(
            'app.providers',
            new \Psalm\Type\Union([\Psalm\Type\Atomic\TLiteralString::make('fallback')]),
        );

        // Laravel's Arr::get returns the stored null when the key exists
        // (array_key_exists === true) — the default is NOT applied.
        $this->assertTrue($type->isNull());
    }

    #[Test]
    public function generalizeDefault_downgrades_literal_scalars(): void
    {
        $input = new \Psalm\Type\Union([
            \Psalm\Type\Atomic\TLiteralString::make('x'),
            new \Psalm\Type\Atomic\TLiteralInt(42),
            new \Psalm\Type\Atomic\TTrue(),
        ]);

        $generalized = ConfigKeyResolver::generalizeDefault($input);

        $this->assertTrue($generalized->hasString());
        $this->assertTrue($generalized->hasInt());
        $this->assertTrue($generalized->hasBool());
        $this->assertFalse($generalized->isSingleStringLiteral());
        $this->assertFalse($generalized->isSingleIntLiteral());
    }

    #[Test]
    public function generalizeDefault_resolves_typed_closure_to_return_type(): void
    {
        $closure = new \Psalm\Type\Atomic\TClosure(
            params: [],
            return_type: new \Psalm\Type\Union([\Psalm\Type\Atomic\TLiteralString::make('bar')]),
        );

        $generalized = ConfigKeyResolver::generalizeDefault(new \Psalm\Type\Union([$closure]));

        $this->assertSame('string', $generalized->getId());
    }

    #[Test]
    public function generalizeDefault_resolves_untyped_closure_to_mixed(): void
    {
        $closure = new \Psalm\Type\Atomic\TClosure();

        $generalized = ConfigKeyResolver::generalizeDefault(new \Psalm\Type\Union([$closure]));

        $this->assertTrue($generalized->isMixed());
    }

    #[Test]
    public function resolveCollectionReturnType_wraps_array_in_collection(): void
    {
        // A list keeps the structural key (`int<0, 2>`) and a value generalized
        // by the reflector (`int`). Keyed arrays follow the identical wrap path
        // but intern literal string keys, which needs a bootstrapped
        // ProjectAnalyzer the unit harness deliberately avoids — that rendering
        // (`Collection<'guard'|'passwords', string>`) is exercised by the type
        // tests instead.
        $repo = $this->fakeRepository(['app.aliases' => [1, 2, 3]]);
        $resolver = new ConfigKeyResolver($repo);

        $type = $resolver->resolveCollectionReturnType('app.aliases');

        $this->assertNotNull($type);
        $this->assertSame('Illuminate\\Support\\Collection<int<0, 2>, int>', $type->getId());
    }

    #[Test]
    public function resolveCollectionReturnType_returns_null_when_key_absent(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        // Absent key → array() resolves the default, whose shape we don't reflect.
        $this->assertNull($resolver->resolveCollectionReturnType('app.missing'));
    }

    #[Test]
    public function resolveCollectionReturnType_returns_null_when_value_not_array(): void
    {
        $repo = $this->fakeRepository(['app.name' => 'Laravel']);
        $resolver = new ConfigKeyResolver($repo);

        // Scalar value → collection() throws InvalidArgumentException at runtime.
        $this->assertNull($resolver->resolveCollectionReturnType('app.name'));
    }

    #[Test]
    public function resolveCollectionReturnType_returns_null_for_empty_array(): void
    {
        $repo = $this->fakeRepository(['app.providers' => []]);
        $resolver = new ConfigKeyResolver($repo);

        // Collection<never, never> is useless for a mutable container — defer to stub.
        $this->assertNull($resolver->resolveCollectionReturnType('app.providers'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function fakeRepository(array $values): ConfigRepository
    {
        return new class ($values) implements ConfigRepository {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values) {}

            #[\Override]
            public function has($key): bool
            {
                return \array_key_exists($key, $this->values);
            }

            #[\Override]
            public function get($key, $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            #[\Override]
            public function all(): array
            {
                return $this->values;
            }

            #[\Override]
            public function set($key, $value = null): void
            {
                if (\is_array($key)) {
                    foreach ($key as $k => $v) {
                        $this->values[$k] = $v;
                    }

                    return;
                }

                $this->values[$key] = $value;
            }

            #[\Override]
            public function prepend($key, $value): void {}

            #[\Override]
            public function push($key, $value): void {}
        };
    }
}
