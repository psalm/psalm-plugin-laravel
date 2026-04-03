<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule;

final class TreeNode
{
    public ?ResolvedRule $self = null;

    /** @var array<string, TreeNode> */
    public array $children = [];
}
