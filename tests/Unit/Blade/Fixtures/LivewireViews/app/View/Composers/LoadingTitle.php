<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\View\View;

final class LoadingTitle
{
    public function compose(View $view): void
    {
        $view->with('title', 'Loading');
    }
}
