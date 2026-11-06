@php
/** @var \Fx\Magic $magic */
@endphp
<p>before</p>
@php
$magic->other = 1;
$magic->{'other'} = 2;
@endphp
