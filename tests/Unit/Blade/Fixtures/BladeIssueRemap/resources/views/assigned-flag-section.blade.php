@section('main')
@php
    $entry = \BladeIssueRemapFixture\MaybeItem::find();
    $hasEntry = $entry !== null && $entry->ready();
@endphp
<x-alert>Hi</x-alert>
@if ($hasEntry)
  <p>{{ $entry->name }}</p>
@endif
@endsection
