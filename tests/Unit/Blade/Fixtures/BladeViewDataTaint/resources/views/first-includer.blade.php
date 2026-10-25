@includeFirst(['pkg::second'], ['q' => request()->input('q')])
@includeFirst(['pkg::missing', 'inc-first-b'], ['q' => request()->input('q')])
@includeFirst(['inc-first', 'inc-first-never'], ['q' => request()->input('q')])
