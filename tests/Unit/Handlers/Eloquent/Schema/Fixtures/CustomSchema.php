<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

use Illuminate\Support\Facades\Schema;

/**
 * Test fixture for custom Schema facade subclass detection.
 * Used by CustomSchemaFacadeTest to verify Schema subclasses are recognized.
 */
final class CustomSchema extends Schema {}
