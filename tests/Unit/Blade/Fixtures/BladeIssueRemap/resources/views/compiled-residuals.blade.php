<div>
  @inertiaHead
  @inertia
  <span x-data="{ tab: @entangle('tab') }"></span>
  @capture($renderBadge)
    <b>badge</b>
  @endcapture
  {{ $renderBadge() }}
</div>
