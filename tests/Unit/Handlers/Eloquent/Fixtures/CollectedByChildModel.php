<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Fixtures;

/**
 * Test fixture: child model without its own #[CollectedBy] — should inherit from parent.
 */
final class CollectedByChildModel extends CollectedByParentModel {}
