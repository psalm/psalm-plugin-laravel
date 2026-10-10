@foreach ([1, 2] as $row)
@include('assigned-after-compact-row', compact('row', 'total'))
@endforeach
@php $total = \BladeIssueRemapFixture\MaybeItem::find(); @endphp
<p>{{ $total?->name }}</p>
