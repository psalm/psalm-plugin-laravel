@php
/** @psalm-taint-sink html */
function mixedLeakScanCardHelper(): void {}
@endphp
@if(\BladeIssueRemapFixture\Gate::userCan('x', $book))
{{ $book->getUrl() }}
@endif
