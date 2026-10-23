<?php /** @var array<string, mixed> $endpoint */ ?>
@if($endpoint['body_params'] ?? false)
x
@endif
@php /** @psalm-var \BladeIssueRemapFixture\BaseModel $book */ @endphp
@if(\BladeIssueRemapFixture\Gate::userCan('x', $book))
{{ $book->getUrl('/c') }}
@endif
<?php
/**
 * @var array{
 *   k?: mixed
 * } $multi
 */
?>
@if($multi['k'] ?? false)
x
@endif
@php /** @var \ArrayObject<int, \Closure(string $value): string> $box */ @endphp
{{ $box->typo() }}
