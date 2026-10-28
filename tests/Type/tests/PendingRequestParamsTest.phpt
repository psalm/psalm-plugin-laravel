--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;

/**
 * Body/query/options params are as wide as Laravel's `array|JsonSerializable|Arrayable`: the valid
 * shape depends on the body format (multipart list, `null` falling back to withBody(), plain object),
 * which a docblock cannot express.
 *
 * @param array<array-key, mixed> $p
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1669
 */
function test_pending_request_params(PendingRequest $r, array $p): void
{
    $r->asMultipart()->post('https://x.test', [['name' => 'f', 'contents' => 'v']]);
    $r->post('https://x.test', null);
    $r->post('https://x.test', new \stdClass());
    $r->post('https://x.test', $p);
    $r->put('https://x.test', $p);
    $r->patch('https://x.test', $p);
    $r->delete('https://x.test', $p);
    $r->get('https://x.test', $p);
    $r->head('https://x.test', $p);
    $r->send('POST', 'https://x.test', $p);
}
?>
--EXPECTF--
