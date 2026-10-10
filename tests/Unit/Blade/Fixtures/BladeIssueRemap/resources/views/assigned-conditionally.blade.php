@if (\BladeIssueRemapFixture\MaybeItem::flip())
@php $picked = \BladeIssueRemapFixture\MaybeItem::find(); @endphp
@endif
<p>{{ $picked?->name }}</p>
@foreach ([1, 2] as $n)
@php $last = $n; @endphp
@endforeach
<p>{{ $last }}</p>
@php $label = strtoupper((string) $label); @endphp
<p>{{ $label }}</p>
