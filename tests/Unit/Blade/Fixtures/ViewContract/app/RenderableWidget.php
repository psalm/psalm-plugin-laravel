<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\Support\Renderable;

final class RenderableWidget implements Renderable
{
    public function render(): string
    {
        return '';
    }
}
