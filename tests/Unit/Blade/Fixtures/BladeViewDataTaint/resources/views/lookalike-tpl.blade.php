@php
$q = request()->input('q');
$maker = new \BladeViewDataTaintFixture\LookAlikeMaker();
echo e($maker->make('lk-partial', ['q' => $q], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])));
@endphp
