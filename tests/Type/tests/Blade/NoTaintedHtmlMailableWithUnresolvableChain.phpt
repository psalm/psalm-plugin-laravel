--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Locks in the conservative `Mailable::with` chain behaviour added in PR-6a:
 * when the receiver-walk cannot resolve to exactly one view name, the
 * dispatcher installs NO sink (consistent with PR-3's "view not in map → no
 * sink" policy). Without this regression test, a future change that adds a
 * whole-data fallback for the null branch would silently start emitting
 * TaintedHtml on the very common dynamic-view and multi-binder patterns
 * below.
 *
 * The positive `Mailable::view('mail.invoice')->with('bio', $tainted)` case
 * (literal view name in the safety map + matching unsafe key → per-key
 * sink) cannot be exercised at the PHPT layer because the booted Testbench
 * app has no fixture blade templates we can mark UNSAFE_KEYS. That case is
 * pinned by the dispatcher-level unit tests in
 * `tests/Unit/Handlers/Views/BladeAwareViewTaintHandlerTest.php`
 * (mailable_with_chained_off_view_installs_per_key_sink and siblings).
 */

// Dynamic view-binder argument. Mailable::view sees a non-literal first arg
// and records no candidate; the chained Mailable::with receiver-walk
// returns null; no sink lands.
function sendDynamic(\Illuminate\Http\Request $request, string $template): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->view($template)->with('bio', $request->input('bio'));
}

// Multi-binder chain. Laravel binds 'a' to $view AND 'b' to $textView
// simultaneously; the resolver refuses to pick one (same soundness rule as
// multi-literal `View::first(['a','b'])`) so the with() call installs no
// sink, even though both 'a' and 'b' could have unsafe keys at runtime.
function sendMultiBinder(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->view('a')->text('b')->with('bio', $request->input('bio'));
}

// Variable-bound chain head — the same null path as PR-4's bare-variable
// `$v->with(...)` case for View::with. The Mailable variant must follow
// the identical conservative policy.
function sendVariableBound(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->view('a');
    $mailable->with('bio', $request->input('bio'));
}
?>
--EXPECTF--

