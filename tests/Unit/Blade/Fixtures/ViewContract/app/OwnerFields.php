<?php

declare(strict_types=1);

namespace ViewContractFixture;

trait OwnerFields
{
    public self $owner;

    public ?self $maybe = null;
}
