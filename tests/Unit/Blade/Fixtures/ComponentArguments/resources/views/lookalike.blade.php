{{-- The compiled statement written by hand, on a class that is no component. --}}
@php $component = \App\Support\Registry::resolve(['count' => 'many'] + (isset($attributes) && $attributes instanceof \Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); @endphp
{{-- Close to the compiled statement, but not it. --}}
@php $component = \App\View\Components\Alert::resolve(['count' => 'many'] + (isset($ready) && $attributes instanceof \Illuminate\Support\Collection ? $attributes->toArray() : [])); @endphp
