--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

/**
 * Regression test for psalm/psalm-plugin-laravel#1443.
 *
 * InteractsWithViews::view() is declared on the trait, not on the test case that
 * uses it — Populator propagates declaring_method_ids['view'] up from the trait,
 * so MissingViewHandler must resolve the role from the trait's own FQCN.
 */
final class WelcomeTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_it_renders(): void
    {
        $this->view('does.not.exist')->assertSee('hello');
        $this->view('welcome')->assertSee('hello');
    }
}
?>
--EXPECTF--
MissingView on line %d: View 'does.not.exist' not found in any of the registered view paths
