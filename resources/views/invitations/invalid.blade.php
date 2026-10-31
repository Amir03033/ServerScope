<x-layouts.guest :title="__('invitations.invalid_title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('invitations.invalid_title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('invitations.invalid_body') }}</p>
    <div class="mt-5 text-sm"><a class="text-accent underline-offset-2 hover:underline" href="{{ route('login') }}">{{ __('auth.login.title') }}</a></div>
</x-layouts.guest>
