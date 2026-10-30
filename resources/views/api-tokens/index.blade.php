@php
    use App\Models\ApiToken;
    use App\Support\Label;
    use App\Support\Time;
@endphp

<x-layouts.app :title="__('api_tokens.title')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('profile.edit') }}">{{ __('api_tokens.profile') }}</a> <span>›</span> {{ __('api_tokens.title') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{!! __('api_tokens.subtitle', ['path' => '<code>/api/v1</code>']) !!}</x-slot:subtitle>

    <div class="grid gap-4">
        @if (session('api_token'))
            <x-secret-token :token="session('api_token')" :title="__('api_tokens.new_token')" id="api-token">
                <p class="mt-2 mb-0 text-sm">{!! __('api_tokens.usage', ['header' => '<code>Authorization: Bearer &lt;token&gt;</code>']) !!}</p>
            </x-secret-token>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
            <section class="ss-panel" aria-labelledby="tokens-title">
                <div class="ss-panel-head"><div><h2 id="tokens-title" class="ss-panel-title">{{ __('api_tokens.tokens') }}</h2></div></div>
                @if ($tokens->isEmpty())
                    <x-empty-state icon="i-key" :title="__('api_tokens.empty_title')">{{ __('api_tokens.empty_text') }}</x-empty-state>
                @else
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('api_tokens.col_name') }}</th><th scope="col">{{ __('api_tokens.col_abilities') }}</th><th scope="col">{{ __('api_tokens.col_last_used') }}</th><th scope="col">{{ __('api_tokens.col_status') }}</th><th scope="col"><span class="sr-only">{{ __('api_tokens.col_actions') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($tokens as $token)
                                    <tr>
                                        <td class="is-primary">{{ $token->name }}<span class="block font-mono text-xs text-muted">{{ $token->prefix }}…</span></td>
                                        <td data-label="{{ __('api_tokens.col_abilities') }}"><span class="flex flex-wrap gap-1">@foreach ($token->abilities as $a)<span class="ss-chip">{{ $a }}</span>@endforeach</span></td>
                                        <td data-label="{{ __('api_tokens.col_last_used') }}" class="text-sm">{{ Time::relative($token->last_used_at) }}</td>
                                        <td data-label="{{ __('api_tokens.col_status') }}">
                                            <span>
                                                @if ($token->revoked_at)<x-status status="closed" :label="__('api_tokens.revoked')" />
                                                @elseif (! $token->isActive())<x-status status="expired" />
                                                @else<x-status status="valid" :label="__('api_tokens.active')" />@if ($token->expires_at)<span class="block text-xs text-muted">{{ __('api_tokens.until', ['date' => Time::date($token->expires_at)]) }}</span>@endif
                                                @endif
                                            </span>
                                        </td>
                                        <td class="is-actions">
                                            @if ($token->isActive())
                                                <form method="POST" action="{{ route('api-tokens.destroy', $token) }}" data-confirm="{{ __('api_tokens.revoke_confirm', ['name' => $token->name]) }}" class="flex justify-end">
                                                    @csrf @method('DELETE')
                                                    <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('api_tokens.revoke') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="ss-panel" aria-labelledby="new-token-title">
                <h2 id="new-token-title" class="ss-panel-title mb-4">{{ __('api_tokens.create_title') }}</h2>
                <form method="POST" action="{{ route('api-tokens.store') }}" class="grid gap-4">
                    @csrf
                    <div>
                        <label class="ss-label" for="name">{{ __('api_tokens.name') }}</label>
                        <input id="name" class="ss-input mt-1.5" name="name" maxlength="100" required placeholder="{{ __('api_tokens.name_placeholder') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                    <fieldset class="m-0 grid gap-2 border-0 p-0">
                        <legend class="ss-label mb-1">{{ __('api_tokens.abilities') }}</legend>
                        @foreach (ApiToken::ABILITIES as $value => $label)
                            <label class="ss-check items-start"><input class="mt-0.5" type="checkbox" name="abilities[]" value="{{ $value }}" @checked($value === 'read') @disabled($value === 'read')> <span>{{ Label::of('api_ability', $value, ApiToken::ABILITIES) }}</span></label>
                        @endforeach
                        <input type="hidden" name="abilities[]" value="read">
                    </fieldset>
                    <div>
                        <label class="ss-label" for="expires_in_days">{{ __('api_tokens.validity') }}</label>
                        <select id="expires_in_days" class="ss-input mt-1.5" name="expires_in_days"><option value="90">{{ __('api_tokens.validities.90') }}</option><option value="30">{{ __('api_tokens.validities.30') }}</option><option value="365">{{ __('api_tokens.validities.365') }}</option><option value="">{{ __('api_tokens.validities.none') }}</option></select>
                    </div>
                    <div><button class="ss-btn ss-btn-primary" type="submit">{{ __('api_tokens.create') }}</button></div>
                </form>
            </section>
        </div>
    </div>
</x-layouts.app>
