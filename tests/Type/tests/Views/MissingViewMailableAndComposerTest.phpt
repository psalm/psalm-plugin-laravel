--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;

function _diMailable(Mailable $mailable): void {
    $mailable->view('mailable-view-missing');
    $mailable->markdown(view: 'mailable-markdown-missing');
    $mailable->text(textView: 'mailable-text-missing');

    $mailable->view('welcome');
    $mailable->markdown('welcome');
    $mailable->text('welcome');
}

function _diViewFactory(Factory $factory, string $dynamic): void {
    $factory->composer('composer-missing', static function (): void {});
    $factory->creator(['welcome', 'creator-missing'], static function (): void {});

    $factory->composer('welcome', static function (): void {});
    $factory->creator(['welcome', 'errors.503'], static function (): void {});

    // Event patterns, dynamic names, and package namespaces are not concrete
    // templates that the plugin can prove missing.
    $factory->composer('*', static function (): void {});
    $factory->composer('partials.*', static function (): void {});
    $factory->composer($dynamic, static function (): void {});
    $factory->composer('mail::html.header', static function (): void {});
}

View::composer('facade-composer-missing', static function (): void {});
View::creator('welcome', static function (): void {});
?>
--EXPECTF--
MissingView on line %d: View 'mailable-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'mailable-markdown-missing' not found in any of the registered view paths
MissingView on line %d: View 'mailable-text-missing' not found in any of the registered view paths
MissingView on line %d: View 'composer-missing' not found in any of the registered view paths
MissingView on line %d: View 'creator-missing' not found in any of the registered view paths
MissingView on line %d: View 'facade-composer-missing' not found in any of the registered view paths
