--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A session read returns what the application stored under its key: the values it chose itself are not input, nor is
 * a key next to one holding input.
 */
function storeSessionData(\Illuminate\Http\Request $request, \Illuminate\Session\Store $session): void {
    $session->put('user_id', 42);
    $session->flash('status', 'saved');
    $session->put('name', $request->input('name'));
    session(['page' => 'home']);
}

function renderSessionData(\Illuminate\Session\Store $session): void {
    echo (int) $session->get('user_id');
    echo (string) $session->get('status');
    echo (string) session('page');
}
?>
--EXPECTF--
