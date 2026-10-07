@aware([null])
@php $n = random_int(0, 1) === 1 ? 'color' : null; @endphp
@aware([$n])
@aware(['color' => 'red', null])
@aware(array(null))
@aware([
    'tone' => 'red',
    null,
])
@aware(['0' => null])
@php $d = ['a' => null, 'x']; @endphp
@aware($d)
@aware([[1]] + // @verbatim<?php foreach ((@endverbatim
['a' => null, 'x'])
