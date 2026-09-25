<div>
@error('field')
<span>{{ $message }}</span>
@enderror
</div>
@php
$arr = ['only'];
echo $arr[1];
@endphp
