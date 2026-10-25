<x-alert :message="request('m')" />
<x-notice :text="request('t')" />
<x-escaped :message="request('m')" />
<x-literal message="hi" />
<x-hidden :message="request('m')" />
<x-unmapped :message="request('m')" />
<x-card :message="request('m')" />
@php($made = $__env->getContainer()->make(\App\View\Components\Made::class, ['message' => request('m')]))
@php(\App\Support\Lookalike::resolve(['message' => request('m')]))
<x-san-e :message="request('m')" />
<x-replaced :message="request('m')" />
<x-renamed :label="request('l')" />
<x-unbound :message="request('m')" />
<x-trait-a :message="request('m')" />
<x-trait-b message="SAFE" />
@component(\App\View\Components\Compo::class, 'compo', ['message' => request('m')])
@endComponentClass
<x-except-lit :message="request('m')" />
<x-except-doc :message="request('m')" />
<x-static-prop :message="request('m')" />
<x-ignored-name :data="request('d')" />
<x-method-wins :message="request('m')" />
<x-shared-x-one :message="request('m')" />
<x-shared-x-two message="lit" />
<x-shared-y-one message="lit" />
<x-shared-y-two :message="request('m')" />
<x-sib-a :message="request('m')" />
<x-sib-b message="lit" />
@php($notContainer = $__env->make(\App\View\Components\NotContainer::class, ['message' => request('m')]))
@php($overridden = (new \App\Support\MyContainer())->make(\App\View\Components\OverriddenMake::class, ['message' => request('m')]))
@php($named = \App\View\Components\NamedArg::resolve(data: ['message' => request('m')]))
@php($spread = \App\View\Components\SpreadArg::resolve(...[['message' => request('m')]]))
@php($spreadSafe = \App\View\Components\SpreadSafe::resolve(['message' => request('m'), ...['message' => 'SAFE']]))
@php($spreadTaint = \App\View\Components\SpreadTaint::resolve(['message' => 'SAFE', ...['message' => request('m')]]))
<x-holder :slot="request('s')">Safe</x-holder>
<x-extra :message="request('m')" />
