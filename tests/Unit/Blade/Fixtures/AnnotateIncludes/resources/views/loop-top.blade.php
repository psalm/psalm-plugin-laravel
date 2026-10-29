@foreach (json_decode('[]') as $member)
@includeIsolated('loop-leaf', ['member' => $member])
@endforeach
