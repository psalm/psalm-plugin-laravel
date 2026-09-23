@php
/** @var \Fx\Magic $magic */
/** @var array<int, \Fx\Magic> $objects */
@endphp
<p>before</p>
{{ $magic->missing }}
@php
$magic->nope = 1;
@endphp
{{ \Fx\Magic::make()->missing }}
{{ $objects[0]->missing }}
@php
\Fx\Magic::make()->nope = 1;
@endphp
