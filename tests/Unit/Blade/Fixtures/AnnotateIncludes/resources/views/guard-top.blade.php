@if ($x !== null)
@foreach ([1] as $n)
@endforeach
@includeIsolated('guard-leaf', ['x' => $x])
@endif
