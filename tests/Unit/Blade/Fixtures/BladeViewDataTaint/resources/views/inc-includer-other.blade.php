@include('inc-other-key', ['t' => request()->input('q')])
@includeIsolated('inc-isolated')
@include('inc-literal', ['q' => 'fixed'])
