<x-layouts.guest :title="__('auth.register.title')">
    <h1 class="ss-auth-title">{{ __('auth.register.title') }}</h1>
    <p class="ss-auth-intro">{{ __('auth.register.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('register') }}" class="ss-auth-form" data-auth-form>
        @csrf
        <input type="hidden" name="plan" value="{{ $plan }}">
        <div class="ss-auth-plan ss-auth-field" data-testid="chosen-plan">
            <span class="ss-auth-plan-icon" aria-hidden="true"><svg class="ico"><use href="#i-sparkles"/></svg></span>
            <span>
                <strong>{{ __('plans.register.chosen', ['plan' => __('plans.names.'.$plan)]) }}</strong>
                <small>{{ $plan === 'free' ? __('plans.register.free_note') : __('plans.register.paid_note') }}</small>
            </span>
        </div>
        <div class="ss-auth-pair">
            <div class="ss-auth-field">
                <label class="ss-label" for="name">{{ __('auth.fields.name') }}</label>
                <input id="name" class="ss-input mt-1.5" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
            </div>
            <div class="ss-auth-field">
                <label class="ss-label" for="team">{{ __('auth.fields.team') }}</label>
                <input id="team" class="ss-input mt-1.5" type="text" name="team" value="{{ old('team') }}" autocomplete="organization" required>
            </div>
        </div>
        <div class="ss-auth-field">
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        </div>
        @include('auth._password', ['id' => 'password', 'label' => __('auth.fields.password'), 'autocomplete' => 'new-password', 'meter' => true])
        @include('auth._password', ['id' => 'password_confirmation', 'label' => __('auth.fields.password_confirmation'), 'autocomplete' => 'new-password'])
        <div class="ss-auth-field">@include('auth._terms')</div>
        <button type="submit" class="ss-btn ss-btn-primary ss-auth-submit"><span>{{ __('auth.register.title') }}</span></button>
    </form>
    <p class="ss-auth-alt"><a class="ss-auth-link" href="{{ route('login') }}">{{ __('auth.register.have_account') }}</a></p>
</x-layouts.guest>
