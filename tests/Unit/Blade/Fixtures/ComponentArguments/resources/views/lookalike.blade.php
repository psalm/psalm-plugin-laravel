{{-- The compiled statement written by hand, on a class that is no component. --}}
@php $component = \App\Support\Registry::resolve(['count' => 'many'] + (isset($attributes) && $attributes instanceof \Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); @endphp
