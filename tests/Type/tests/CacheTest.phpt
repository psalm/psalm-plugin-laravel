--FILE--
<?php declare(strict_types=1);

function test_cache_remember_returns_template_type(\Illuminate\Cache\Repository $cache): int
{
    return $cache->remember('key', 60, fn(): int => 42);
}

function test_cache_remember_forever_returns_template_type(\Illuminate\Cache\Repository $cache): string
{
    return $cache->rememberForever('key', fn(): string => 'value');
}

function test_cache_sear_returns_template_type(\Illuminate\Cache\Repository $cache): bool
{
    return $cache->sear('key', fn(): bool => true);
}

function test_cache_flexible_returns_template_type(\Illuminate\Cache\Repository $cache): int
{
    return $cache->flexible('key', [60, 120], fn(): int => 42);
}
?>
--EXPECTF--
