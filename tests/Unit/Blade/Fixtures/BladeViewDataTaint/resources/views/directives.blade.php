@php $passed = ['q' => request()->input('q')]; @endphp
@includeIf('inc-if', ['q' => request()->input('q')])
@includeWhen(true, 'inc-when', ['q' => request()->input('q')])
@includeUnless(false, 'inc-unless', ['q' => request()->input('q')])
@includeFirst(['inc-missing', 'inc-first'], ['q' => request()->input('q')])
@include('inc-var', $passed)
@extends('inc-extends', ['q' => request()->input('q')])
