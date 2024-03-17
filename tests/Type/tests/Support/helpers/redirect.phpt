--FILE--
<?php declare(strict_types=1);

function test_redirect_call_without_args_should_return_Redirector(): \Illuminate\Routing\Redirector {
    return redirect();
}

function test_redirect_call_with_single_arg_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
    return redirect('foo');
}

function test_redirect_call_with_two_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
    return redirect('foo', 301);
}

function test_redirect_call_with_three_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
    return redirect('foo', 301, ['Accept' => 'text/html']);
}

function test_redirect_call_with_four_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
    return redirect('foo', 301, ['Accept' => 'text/html'], true);
}
?>
--EXPECTF--
