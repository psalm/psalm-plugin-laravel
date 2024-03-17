--FILE--
<?php declare(strict_types=1);

function test_url_without_args_should_return_UrlGenerator(): \Illuminate\Contracts\Routing\UrlGenerator {
    return url();
}

function test_url_with_single_arg_should_return_string(): string {
    return url('example.com');
}

function test_url_with_two_args_should_return_string(): string {
    return url('example.com', ['a' => 42]);
}

function test_url_with_three_args_should_return_string(): string {
    return url('example.com', ['a' => 42], true);
}
?>
--EXPECTF--
