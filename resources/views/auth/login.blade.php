<x-layouts.guest :title="__('auth.login.title')">
    <h1 class="ss-auth-title">{{ __('auth.login.heading') }}</h1>
    <p class="ss-auth-intro">{{ __('auth.login.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('login') }}" class="ss-auth-form" data-auth-form data-auth-ghost>
        @csrf

        <div class="ss-auth-field">
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                   @error('email') aria-invalid="true" @enderror>
        </div>

        @include('auth._password', ['id' => 'password', 'label' => __('auth.fields.password'), 'autocomplete' => 'current-password'])

        <div class="ss-auth-field ss-auth-row">
            <label class="ss-check">
                <input type="checkbox" name="remember" value="1">
                {{ __('auth.login.remember') }}
            </label>
            <a class="ss-auth-link" href="{{ route('password.request') }}">{{ __('auth.login.forgot') }}</a>
        </div>

        <button type="submit" class="ss-btn ss-btn-primary ss-auth-submit"><span>{{ __('auth.login.submit') }}</span></button>
    </form>

    @if (\App\Support\Demo::enabled())
        <div class="ss-auth-or"><span>{{ __('auth.login.or') }}</span></div>
        <a class="ss-auth-demo" href="{{ route('demo.enter') }}">
            <span class="ss-auth-demo-icon" aria-hidden="true"><svg class="ico"><use href="#i-play"/></svg></span>
            <span><strong>{{ __('auth.login.demo') }}</strong><small>{{ __('auth.login.demo_text') }}</small></span>
        </a>
    @endif

    @if (config('serverscope.tenancy.registration_enabled'))
        <p class="ss-auth-alt">{{ __('auth.login.new_here') }} <a class="ss-auth-link" href="{{ route('register') }}">{{ __('auth.login.create') }}</a></p>
    @endif
</x-layouts.guest>
