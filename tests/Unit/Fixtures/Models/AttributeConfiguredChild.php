<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

/**
 * Concrete child that declares no config attributes of its own — its `#[Hidden]`/`#[Appends]` come
 * entirely from {@see AttributeConfiguredBase} via the `classAttribute()` ancestor walk. Laravel 13.0+
 * only.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class AttributeConfiguredChild extends AttributeConfiguredBase {}
