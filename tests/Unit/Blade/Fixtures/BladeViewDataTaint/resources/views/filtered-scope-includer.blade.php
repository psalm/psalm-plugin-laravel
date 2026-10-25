@php $q = request()->input('q'); @endphp
@includeIsolated('filtered-scope-partial', array_diff_key(get_defined_vars(), ['q' => 1]))
