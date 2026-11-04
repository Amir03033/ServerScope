@props(['class' => 'h-6 w-6'])
{{-- The ServerScope pulse with a beat running along it. Real paths, not <use>: Safari on iOS does not animate the dash of a <use> copy. --}}
<svg {{ $attributes->class(['ico ss-live-logo text-accent [stroke-width:2]', $class]) }} viewBox="0 0 24 24" aria-hidden="true"><path class="base" d="M2 12h4l3-8 5 16 3-8h5"/><path class="beat" d="M2 12h4l3-8 5 16 3-8h5"/></svg>
