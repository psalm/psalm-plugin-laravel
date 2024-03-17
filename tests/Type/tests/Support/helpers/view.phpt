--FILE--
<?php declare(strict_types=1);

function view_without_args(): \Illuminate\Contracts\View\Factory
{
    return view();
}

function view_with_one_arg(): Illuminate\Contracts\View\View
{
    return view('home');
}

function view_with_two_args(): \Illuminate\Contracts\View\View
{
    return view('home', []);
}

function view_with_three_args(): \Illuminate\Contracts\View\View
{
    return view('home', [], []);
}

function view_make_with_two_args(): \Illuminate\Contracts\View\View
{
    return view()->make('home', []);
}

function view_make_with_three_args(): \Illuminate\Contracts\View\View
{
    return view()->make('home', [], []);
}
?>
--EXPECTF--
