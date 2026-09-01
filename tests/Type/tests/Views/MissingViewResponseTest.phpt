--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// response() (zero-arg helper) is typed on the contract Illuminate\Contracts\Routing\ResponseFactory.
response()->view('response-helper-missing');
response()->view('welcome');

// is_array($view) forwards to Factory::first() semantics — a candidate list.
response()->view(['response-array-missing-a', 'response-array-missing-b']);
response()->view(['welcome', 'response-array-missing-c']);

// Response facade.
\Illuminate\Support\Facades\Response::view('response-facade-missing');
\Illuminate\Support\Facades\Response::view('welcome');

// Concrete ResponseFactory (e.g. injected directly rather than via the helper).
function _diResponseFactory(\Illuminate\Routing\ResponseFactory $responseFactory): void {
    $responseFactory->view('response-concrete-missing');
}

// Contract-typed ResponseFactory.
function _diResponseFactoryContract(\Illuminate\Contracts\Routing\ResponseFactory $responseFactory): void {
    $responseFactory->view('response-contract-missing');
}
?>
--EXPECTF--
MissingView on line %d: View 'response-helper-missing' not found in any of the registered view paths
MissingView on line %d: None of the views 'response-array-missing-a', 'response-array-missing-b' were found in any of the registered view paths
MissingView on line %d: View 'response-facade-missing' not found in any of the registered view paths
MissingView on line %d: View 'response-concrete-missing' not found in any of the registered view paths
MissingView on line %d: View 'response-contract-missing' not found in any of the registered view paths
