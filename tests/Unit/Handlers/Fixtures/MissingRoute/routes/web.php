<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/dashboard', static fn(): string => 'dashboard')->name('dashboard');
Route::get('/posts/{id}', static fn(int $id): string => (string) $id)->name('posts.show');
