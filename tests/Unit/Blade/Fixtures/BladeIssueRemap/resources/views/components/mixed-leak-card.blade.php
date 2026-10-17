@if(\BladeIssueRemapFixture\Gate::userCan('x', $book))
{{ $book->getUrl() }}
@endif
@if($meta['k'] ?? false) x @endif
