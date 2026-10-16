@if($endpoint['body_params'] ?? false)
x
@endif
@if($thing?->prop)
y
@endif
@if(\BladeIssueRemapFixture\Gate::userCan('x', $book))
{{ $book->getUrl('/c') }}
@endif
{{ strip_tags(str_replace(["\n"], ' ', $status['content'])) }}
