<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
