{{ $member?->slug }}
@isset($member)
@includeIsolated('isset-leaf', ['member' => $member])
@endisset
