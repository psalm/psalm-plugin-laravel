<?php
/**
 * @var \Closure(\Illuminate\View\Component): string $takesParent
 * @var \Closure(int): \ViewContractFixture\VcBase $retParent
 * @var \Closure(int): \ViewContractFixture\ComponentInherited $traitSelf
 * @var \Closure(int): \ViewContractFixture\VcBase $traitParent
 */
?>
<p>{{ $takesParent($traitSelf(1)) }} {{ $retParent(1)::class }} {{ $traitParent(1)::class }}</p>
