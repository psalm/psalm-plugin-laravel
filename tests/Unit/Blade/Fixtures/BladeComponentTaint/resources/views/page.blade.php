<x-alert :message="request('m')" />
<x-notice :text="request('t')" />
<x-escaped :message="request('m')" />
<x-literal message="hi" />
<x-hidden :message="request('m')" />
<x-unmapped :message="request('m')" />
<x-card :message="request('m')" />
@php($made = $__env->getContainer()->make(\App\View\Components\Made::class, ['message' => request('m')]))
@php(\App\Support\Lookalike::resolve(['message' => request('m')]))
