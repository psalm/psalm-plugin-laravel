@aware(['disabled' => false])
@aware(['count' => 0])
@aware(['on' => true])
@aware(['items' => []])
@aware(['size' => 'md', 'flag' => false])
@aware(['a' => null, 'b' => false])
@php $__env->getConsumableComponentData(false); @endphp
@php $__env->getConsumableComponentData([]); @endphp
<span>{{ $disabled ? 'y' : 'n' }}{{ $count }}{{ $size }}</span>
