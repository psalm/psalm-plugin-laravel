{{-- @var non-empty-string $label --}}
<div>
@php /** @psalm-trace $title $attributes $componentName $isActive $format $label $footer $untyped $summary */ $probe = [$title, $attributes, $componentName, $isActive, $format, $label, $footer, $untyped, $summary]; @endphp
@php $items = array_values($items); @endphp
@php /** @psalm-trace $items */; @endphp
</div>
