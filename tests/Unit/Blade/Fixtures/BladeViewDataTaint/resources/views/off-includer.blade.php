@php $s = request()->input('q'); @endphp
@includeWhen(false, 'off-when', ['q' => request()->input('q')])
@includeWhen(false, 'off-when-scope')
