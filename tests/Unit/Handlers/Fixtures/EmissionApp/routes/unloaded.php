<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Never passed to withRouting(): stands in for a package provider that returns before loadRoutesFrom().
Route::get('/unloaded', static fn(): string => 'unloaded')->name('feature.unloaded');
