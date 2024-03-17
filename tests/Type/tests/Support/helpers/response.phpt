--FILE--
<?php declare(strict_types=1);

function response_without_args(): \Illuminate\Contracts\Routing\ResponseFactory
{
    return response();
}

function response_with_single_empty_string_arg(): \Illuminate\Http\Response
{
    return response('');
}

function response_with_single_view_arg(): \Illuminate\Http\Response
{
    return response(view('home'));
}

// empty content response
function response_with_single_null_arg(): \Illuminate\Http\Response
{
    return response(null);
}

function response_with_single_array_arg(): \Illuminate\Http\Response
{
    $jsonData = ['a' => 42];
    return response($jsonData);
}

function response_with_first_view_arg(): \Illuminate\Http\Response
{
    return response(view('home'));
}

function response_with_two_args(): \Illuminate\Http\Response
{
    return response('content', 200);
}

function response_with_three_args(): \Illuminate\Http\Response
{
    return response('content', 200, ['Accept' => 'text/html']);
}

function response_called_with_no_arguments_returns_an_instance_of_ResponseFactory(): \Illuminate\Contracts\Routing\ResponseFactory {
    return response();
}

function response_called_with_arguments_returns_an_instance_of_response(): \Illuminate\Http\Response {
    return response('ok');
}
?>
--EXPECTF--
