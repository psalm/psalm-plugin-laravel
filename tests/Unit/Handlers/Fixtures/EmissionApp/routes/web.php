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

    // Only the group prefix and the leaf are literals; the composed name is never spelled out, and the bare leaf is no definition.
    Route::name('barcode.')->group(static function (): void {
        Route::get('/barcode', static fn(): string => 'barcode')->name('index');
    });

    // A prefix needs no trailing dot: this registers `dash-leaf`, never the bare `leaf`.
    Route::name('dash-')->group(static function (): void {
        Route::get('/dash', static fn(): string => 'dash')->name('leaf');
    });

    // The registrar prefix is not a route name, `Route::view()` data is not a route action, and
    // named group attributes still prefix their routes.
    Route::name('res-')->resource('things', \stdClass::class);
    Route::view('/view-data', 'page', ['as' => 'view_data']);
    Route::group(attributes: ['as' => 'named.'], routes: static function (): void {
        Route::get('/named', static fn(): string => 'named')->name('named_leaf');
    });

    // Unknown is never treated as absent: an unlisted fluent method, group attributes that are an
    // expression, and an action with a spread may each change the final name.
    Route::middleware('web')->attribute('as', 'unlisted.')->get('/unlisted', static fn(): string => 'unlisted')->name('unlisted_leaf');
    Route::group(array_merge(['as' => 'merged.']), static function (): void {
        Route::get('/merged', static fn(): string => 'merged')->name('merged_leaf');
    });
    Route::get('/spread', ['uses' => static fn(): string => 'spread', 'as' => 'spread_old', ...['as' => 'spread_actual']]);
}
