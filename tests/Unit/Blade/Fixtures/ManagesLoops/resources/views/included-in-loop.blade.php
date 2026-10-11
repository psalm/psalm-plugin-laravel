{{-- Rendered by @include from inside a loop: $loop comes from the prelude, not a @foreach here. --}}
@php /** @psalm-trace $parentIteration */ $parentIteration = $loop->parent?->iteration; @endphp
