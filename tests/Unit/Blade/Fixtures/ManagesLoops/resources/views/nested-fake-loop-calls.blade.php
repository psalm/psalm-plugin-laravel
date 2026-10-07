@php
if (false) { $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); }
@endphp
@foreach ((new \Fx\Source)->items() as $item)
    {{ $item }}{{ $loop->parent->iteration }}
@endforeach
@php
if (false) { $__env->popLoop(); $loop = $__env->getLastLoop(); }
@endphp
