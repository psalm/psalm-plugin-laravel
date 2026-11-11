<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

#[ModelMakeTenantScopedBy(ModelMakeTenantScope::class)]
class ModelMakeScopedBySubclassModel extends Model {}
