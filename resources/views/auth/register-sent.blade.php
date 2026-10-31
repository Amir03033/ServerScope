<x-layouts.guest :title="__('auth.register.sent_title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.register.sent_title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.register.sent_body') }}</p>
    <div class="mt-5 text-sm"><a class="text-accent underline-offset-2 hover:underline" href="{{ route('login') }}">{{ __('auth.register.have_account') }}</a></div>
</x-layouts.guest>
