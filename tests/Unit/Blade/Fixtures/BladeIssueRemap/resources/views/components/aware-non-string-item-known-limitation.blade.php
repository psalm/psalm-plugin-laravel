@aware([null])
@php $maybe = random_int(0, 1) === 1 ? 'color' : null; @endphp
@aware([$maybe])
@aware(['color' => 'red', null])
