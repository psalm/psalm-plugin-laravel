@php
/** @var \Fx\Magic $magic */
@endphp
<p>before</p>
{{ $magic->missing }}
@php
$magic->nope = 1;
@endphp
