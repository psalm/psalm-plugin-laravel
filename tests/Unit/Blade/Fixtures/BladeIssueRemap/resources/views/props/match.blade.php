@props(['size' => 'lg'])
<div class="{{ match ($size) { 'lg' => 'text-lg', 'sm' => 'text-sm', default => 'text-base' } }}"></div>
@if ($size === 'xl')
    <span>xl</span>
@endif
