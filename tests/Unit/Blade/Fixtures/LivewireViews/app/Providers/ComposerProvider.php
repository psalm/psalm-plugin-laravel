<?php

declare(strict_types=1);

namespace App\Providers;

use App\View\Composers\LoadingTitle;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class ComposerProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('livewire.composed', LoadingTitle::class);
        // A closure composer: its keys cannot be read.
        View::composer('livewire.composed-closure', static function (\Illuminate\View\View $view): void {
            $view->with('page', 'first');
        });
    }
}
