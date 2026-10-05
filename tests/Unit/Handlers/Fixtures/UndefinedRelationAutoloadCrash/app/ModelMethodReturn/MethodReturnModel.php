<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\ModelMethodReturn;

use Illuminate\Database\Eloquent\Model;

final class MethodReturnModel extends Model
{
    public function summary(): DeprecatedSummary
    {
        return new DeprecatedSummary();
    }
}
