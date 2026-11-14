<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/dashboard', static fn(): string => 'dashboard')->name('dashboard');
Route::get('/posts/{id}', static fn(int $id): string => (string) $id)->name('posts.show');

// Defined in source but never registered by the analysed boot (a feature flag that is off). The
// UnregisteredRouteName rule must treat these literal names as conditionally registered.
if (\PHP_INT_SIZE === 0) {
    Route::get('/disabled', static fn(): string => 'disabled')->name('feature.disabled');
    Route::get('/as-form', ['as' => 'feature.as_form', 'uses' => static fn(): string => 'as-form']);

    // Only the group prefix and the leaf are literals; the composed name is never spelled out.
    Route::name('barcode.')->group(static function (): void {
        Route::get('/barcode', static fn(): string => 'barcode')->name('index');
    });
}
