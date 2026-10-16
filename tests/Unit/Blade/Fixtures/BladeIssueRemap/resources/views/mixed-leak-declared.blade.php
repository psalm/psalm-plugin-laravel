<?php /** @var array<string, mixed> $endpoint */ ?>
@if($endpoint['body_params'] ?? false)
x
@endif
@php /** @psalm-var \BladeIssueRemapFixture\BaseModel $book */ @endphp
@if(\BladeIssueRemapFixture\Gate::userCan('x', $book))
{{ $book->getUrl('/c') }}
@endif
