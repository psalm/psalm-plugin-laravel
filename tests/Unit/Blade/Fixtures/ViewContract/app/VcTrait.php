<?php

declare(strict_types=1);

namespace ViewContractFixture;

trait VcTrait
{
    public function traitSelf(int $x): self
    {
        return $this;
    }

    public function traitParent(int $x): parent
    {
        return $this;
    }
}
