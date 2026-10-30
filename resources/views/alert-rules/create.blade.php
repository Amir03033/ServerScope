<x-layouts.app :title="__('alert_rules.create.title')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('alert-rules.index') }}">{{ __('alert_rules.title') }}</a> <span>›</span> {{ __('alert_rules.crumb_new') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('alert_rules.create.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('alert_rules.create.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('alert-rules.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('alert_rules.back') }}</a>
    </x-slot:actions>

    <section class="ss-panel max-w-4xl" aria-labelledby="form-title">
        <div class="ss-panel-head"><div><h2 id="form-title" class="ss-panel-title">{{ __('alert_rules.config_title') }}</h2><p class="ss-panel-sub">{{ __('alert_rules.create.panel_text') }}</p></div></div>
        @include('alert-rules.form', ['rule' => $rule])
    </section>
</x-layouts.app>
