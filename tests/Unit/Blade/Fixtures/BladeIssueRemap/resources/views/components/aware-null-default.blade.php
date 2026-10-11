@props(['value' => null])
@aware(['selection' => null])

<option value="{{ $value }}" @selected($selection === $value)>{{ $slot }}</option>
@php $__env->getConsumableComponentData(null); @endphp
@php $maybe = random_int(0, 1) === 1 ? 'x' : null; @endphp
@aware(['other' => $maybe])
