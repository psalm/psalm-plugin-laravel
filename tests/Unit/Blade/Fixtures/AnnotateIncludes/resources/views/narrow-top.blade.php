@includeIsolated('narrow-plain', ['member' => $member])
@if ($member !== null)
@includeIsolated('narrow-notnull', ['member' => $member])
@endif
