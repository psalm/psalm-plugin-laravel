<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

final class StaticValue
{
    /** `newQuery()` returns `Builder<Model&static>`; the message must not carry the `&static`. */
    public function render(Model $model): View
    {
        return view('inc.override', ['title' => $model->newQuery()]);
    }
}
