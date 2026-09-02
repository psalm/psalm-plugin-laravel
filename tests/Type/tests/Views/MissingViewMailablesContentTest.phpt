--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Content as MailContent;

new Content(view: 'content-view-missing');
new Content(html: 'content-html-view-missing');
new Content(text: 'content-text-missing');
new Content(markdown: 'content-markdown-missing');

new Content('content-positional-view-missing', 'welcome', 'errors.503', 'welcome');

new Content(
    view: 'welcome',
    html: 'errors.503',
    text: 'welcome',
    markdown: 'errors.503',
    htmlString: '<h1>This is rendered HTML, not a view name.</h1>',
);

// Alias resolution reaches the same constructor contract.
new MailContent(view: 'content-alias-missing');

function _dynamicContent(?string $view): Content {
    return new Content(view: $view);
}
?>
--EXPECTF--
MissingView on line %d: View 'content-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'content-html-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'content-text-missing' not found in any of the registered view paths
MissingView on line %d: View 'content-markdown-missing' not found in any of the registered view paths
MissingView on line %d: View 'content-positional-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'content-alias-missing' not found in any of the registered view paths
