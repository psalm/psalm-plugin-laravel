<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\HigherOrderProxy;

/** @param DeprecatedProxyCollection<int, ProxyItem> $items */
function drive_higher_order_map(DeprecatedProxyCollection $items): void
{
    $labels = $items->map->label();
    /** @psalm-check-type-exact $labels = DeprecatedProxyCollection<int, string> */
}

/** @param DeprecatedProxyCollection<int, ProxyItem> $items */
function drive_higher_order_flat_map(DeprecatedProxyCollection $items): void
{
    $labels = $items->flatMap->label();
    /** @psalm-check-type-exact $labels = DeprecatedProxyCollection<array-key, mixed> */
}

/** @param DeprecatedProxyCollection<int, ProxyItem> $items */
function drive_higher_order_partition(DeprecatedProxyCollection $items): void
{
    $parts = $items->partition->label();
    /** @psalm-check-type-exact $parts = DeprecatedProxyCollection<int, DeprecatedProxyCollection<int, ProxyItem>> */
}
