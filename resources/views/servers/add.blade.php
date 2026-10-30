@php use App\Support\Time; @endphp

<x-layouts.app :title="__('servers.add_button')">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">{{ __('servers.title') }}</a> <span>›</span> {{ __('servers.add_short') }}
    </x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('servers.add.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('servers.back') }}</a>
    </x-slot:actions>

    <div class="grid gap-4">
        <section class="ss-panel" aria-labelledby="linux-title">
            <h2 id="linux-title" class="ss-panel-title mb-4">{{ __('servers.add.linux_title') }}</h2>

            @if ($urlProblem)
                <div class="ss-alert ss-alert-error" role="alert">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>
                        <strong>{{ __('servers.add.disabled_title') }}</strong>
                        <p class="m-0 mt-1.5 text-sm">{{ $urlProblem }}</p>
                    </div>
                </div>
            @elseif (! $isAdmin)
                <p class="m-0 text-muted">{{ __('servers.add.admins_only') }}</p>
            @else
                <div class="ss-alert ss-alert-warning mb-5" role="note">
                    <svg class="ico"><use href="#i-shield"/></svg>
                    <div>
                        <strong>{{ __('servers.add.root_warning') }}</strong>
                        {!! __('servers.add.root_text', ['user' => '<code>serverscope</code>', 'dir' => '<code>/opt/serverscope-agent</code>', 'service' => '<code>serverscope-agent</code>']) !!}
                        <a class="text-accent" href="{{ $scriptUrl }}" target="_blank" rel="noopener">{{ __('servers.add.view_script') }}</a>.
                    </div>
                </div>

                @if ($newToken)
                    <ol class="ss-steps">
                        <li>
                            <div class="ss-step-title">{{ __('servers.add.step_run') }} <span class="font-normal text-muted">{{ __('servers.add.step_run_hint') }}</span></div>
                            <div class="ss-terminal mt-2">
                                <div class="flex items-center justify-between gap-2 border-b border-[#1f1f23] px-3 py-1.5">
                                    <span class="text-xs text-[#a1a1aa]">{{ __('servers.add.command_label') }}</span>
                                    <button class="ss-copy" type="button" data-copy-from="install-command">{{ __('common.copy') }}</button>
                                </div>
                                <pre id="install-command" aria-label="{{ __('servers.add.command_label') }}">{{ $command }}</pre>
                            </div>
                        </li>
                        <li>
                            <div class="ss-step-title">{{ __('servers.add.step_token') }}</div>
                            <p class="m-0 mt-1 mb-3 text-sm text-muted">{{ __('servers.add.step_token_text') }}</p>
                            <x-secret-token :token="$newToken" id="enrollment-token" :title="__('servers.add.token_title', ['minutes' => $ttl])" />
                        </li>
                        <li>
                            <div class="ss-step-title">{{ __('servers.add.step_wait') }}</div>
                            <div class="ss-enroll-status mt-2" data-enroll-status data-status-url="{{ route('enrollment-tokens.status', $newTokenId) }}"
                                 data-labels='@json(__('servers.add.status'))' role="status" aria-live="polite">
                                <span class="ss-dot ss-dot-warn ss-live-dot" data-dot aria-hidden="true"></span>
                                <span data-text>{{ __('servers.add.status.waiting') }}</span>
                                <a class="text-accent" data-link hidden href="#">{{ __('servers.add.status.go') }}</a>
                            </div>
                        </li>
                    </ol>
                @else
                    <form method="POST" action="{{ route('enrollment-tokens.store') }}" class="flex flex-wrap gap-2">
                        @csrf
                        <label for="label" class="sr-only">{{ __('servers.add.label') }}</label>
                        <input id="label" class="ss-input !w-auto min-w-0 flex-1 sm:max-w-sm" type="text" name="label" maxlength="100" placeholder="{{ __('servers.add.label_placeholder') }}">
                        <button type="submit" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-key"/></svg> {{ __('servers.add.create_token') }}</button>
                    </form>
                    <p class="m-0 mt-2 text-sm text-muted">{{ __('servers.add.token_help', ['minutes' => $ttl]) }}</p>
                @endif

                <p class="m-0 mt-5 border-t border-line pt-4 text-xs text-muted">
                    {{ __('servers.add.checksum_note') }}
                    {!! __('servers.add.env_note', ['env' => '<code>SERVERSCOPE_ENROLL_TOKEN</code>']) !!}
                </p>
            @endif
        </section>

        @if ($isAdmin && $pending->isNotEmpty())
            <section class="ss-panel" aria-labelledby="pending-title">
                <h2 id="pending-title" class="ss-panel-title mb-3">{{ __('servers.add.pending_title') }}</h2>
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('servers.add.col_token') }}</th><th scope="col">{{ __('servers.add.col_label') }}</th><th scope="col">{{ __('servers.add.col_creator') }}</th><th scope="col">{{ __('servers.add.col_expires') }}</th><th scope="col"><span class="sr-only">{{ __('servers.index.col_actions') }}</span></th></tr></thead>
                        <tbody>
                            @foreach ($pending as $token)
                                <tr>
                                    <td class="is-primary font-mono text-xs">{{ $token->prefix }}…</td>
                                    <td data-label="{{ __('servers.add.col_label') }}">{{ $token->label ?? '—' }}</td>
                                    <td data-label="{{ __('servers.add.col_creator') }}">{{ $token->creator?->name ?? '—' }}</td>
                                    <td data-label="{{ __('servers.add.col_expires') }}" class="whitespace-nowrap">{{ Time::dateTime($token->expires_at) }}</td>
                                    <td class="is-actions">
                                        <form method="POST" action="{{ route('enrollment-tokens.destroy', $token) }}" class="flex justify-end" data-confirm="{{ __('servers.add.revoke_confirm', ['prefix' => $token->prefix]) }}">
                                            @csrf @method('DELETE')
                                            <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('servers.add.revoke') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($isAdmin && $recent->isNotEmpty())
            <section class="ss-panel" aria-labelledby="recent-title">
                <h2 id="recent-title" class="ss-panel-title mb-2">{{ __('servers.add.recent_title') }}</h2>
                @foreach ($recent as $token)
                    <div class="ss-row">
                        <span class="ss-row-main">
                            <strong>
                                @if ($token->server)
                                    <a class="text-fg no-underline hover:text-accent" href="{{ route('servers.show', $token->server) }}">{{ $token->server->name }}</a>
                                @else
                                    {{ __('servers.deleted_server') }}
                                @endif
                            </strong>
                            <span class="ss-row-sub">{{ Time::dateTime($token->used_at) }} · {{ $token->server?->last_seen_at ? __('servers.add.connected') : __('servers.add.waiting_first') }}</span>
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="ss-panel" aria-labelledby="other-title">
            <h2 id="other-title" class="ss-panel-title mb-2">{{ __('servers.add.other_title') }}</h2>
            <ul class="m-0 grid gap-1.5 pl-5 text-sm">
                @if ($isAdmin)
                    <li><a class="text-accent" href="{{ route('servers.create') }}">{{ __('servers.add.manual') }}</a>{{ __('servers.add.manual_text') }}</li>
                @endif
                <li>{{ __('servers.add.bash_agent') }}</li>
            </ul>
        </section>
    </div>
</x-layouts.app>
