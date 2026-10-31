<x-layouts.guest :title="__('auth.verify.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.verify.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.verify.intro', ['email' => auth()->user()->email]) }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
        @csrf
        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.verify.resend') }}</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="ss-btn w-full">{{ __('auth.verify.logout') }}</button>
    </form>
</x-layouts.guest>
