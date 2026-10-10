--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\View\View;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1776
 *
 * view(), Component::render() and View::with() are all typed as the View contract,
 * which extends only Renderable. Blade's `{{ $view }}` compiles to e($view).
 */
function test_e_accepts_view_contract(View $view): string
{
    return e($view);
}

function test_e_accepts_view_helper_result(): string
{
    return e(view('welcome'));
}
?>
--EXPECTF--
