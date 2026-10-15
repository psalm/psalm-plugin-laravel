<?php

declare(strict_types=1);

namespace ViewContractFixture;

trait InvokableSelf
{
    public function me(): self
    {
        return $this;
    }
}
