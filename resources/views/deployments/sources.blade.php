@php
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $newSecret = session('github_secret');
    $envLine = 'SERVERSCOPE_DEPLOY_PATHS=myapp=/var/www/myapp';
    $accessLine = "sudo setfacl -m u:serverscope:x /var/www /var/www/myapp\nsudo setfacl -R -m u:serverscope:rX /var/www/myapp/.git";
    $restartLine = 'sudo systemctl restart serverscope-agent';
    $connectOpen = $errors->hasAny(['repository', 'application', 'environment', 'deploy_on', 'branch', 'resources']);
@endphp

<x-layouts.app :title="__('deploy_sources.title')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('deployments.index') }}">{{ __('deployments.title') }}</a> <span>›</span> {{ __('deploy_sources.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('deploy_sources.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('deploy_sources.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('deployments.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('deploy_sources.back') }}</a>
    </x-slot:actions>

    <div class="grid gap-4">
        {{-- On the server: the agent watches git folders. --}}
        <section class="ss-panel" aria-labelledby="agent-title">
            <div class="ss-panel-head">
                <div class="flex items-start gap-3">
                    <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-server"/></svg></span>
                    <div class="min-w-0">
                        <h2 id="agent-title" class="ss-panel-title">{{ __('deploy_sources.agent.title') }} <span class="ss-chip ss-chip-ok ml-1 align-middle">{{ __('deploy_sources.agent.badge') }}</span></h2>
                        <p class="ss-panel-sub max-w-3xl">{{ __('deploy_sources.agent.text') }}</p>
                    </div>
                </div>
            </div>

            @if ($watches->isNotEmpty())
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('deploy_sources.agent.col_app') }}</th><th scope="col">{{ __('deploy_sources.agent.col_server') }}</th><th scope="col">{{ __('deploy_sources.agent.col_branch') }}</th><th scope="col">{{ __('deploy_sources.agent.col_commit') }}</th><th scope="col">{{ __('deploy_sources.agent.col_state') }}</th></tr></thead>
                        <tbody>
                            @foreach ($watches as $watch)
                                @php $stale = ! $watch->last_seen_at || $watch->last_seen_at->lt(now()->subMinutes(10)); @endphp
                                <tr>
                                    <td class="is-primary"><strong class="font-semibold">{{ $watch->name }}</strong></td>
                                    <td data-label="{{ __('deploy_sources.agent.col_server') }}"><a class="ss-hit text-fg no-underline hover:text-accent" href="{{ route('servers.show', $watch->server_id) }}">{{ $watch->server?->name }}</a></td>
                                    <td data-label="{{ __('deploy_sources.agent.col_branch') }}">{{ $watch->branch ?? '—' }}</td>
                                    <td data-label="{{ __('deploy_sources.agent.col_commit') }}"><code class="text-xs">{{ $watch->commit_sha ? substr($watch->commit_sha, 0, 7) : '—' }}</code></td>
                                    <td data-label="{{ __('deploy_sources.agent.col_state') }}">
                                        @if ($watch->error)
                                            <x-status status="error" :label="__('deploy_sources.agent.errors.'.$watch->error)" />
                                        @else
                                            <x-status :status="$stale ? 'unknown' : 'healthy'" :label="__('deploy_sources.agent.watching')" />
                                        @endif
                                        <span class="mt-1 block text-xs text-muted">{{ $watch->changed_at ? __('deploy_sources.agent.changed', ['time' => Time::relative($watch->changed_at)]) : __('deploy_sources.agent.seen', ['time' => Time::relative($watch->last_seen_at)]) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <details class="ss-setup" @if ($watches->isEmpty()) open @endif>
                <summary>{{ __('deploy_sources.agent.show_setup') }}</summary>
                <ol class="ss-steps mt-4">
                    <li>
                        <div class="ss-step-title">{{ __('deploy_sources.agent.step_env') }}</div>
                        <x-terminal class="mt-2" id="deploy-env" label="/opt/serverscope-agent/.env">{{ $envLine }}</x-terminal>
                    </li>
                    <li>
                        <div class="ss-step-title">{{ __('deploy_sources.agent.step_access') }}</div>
                        <x-terminal class="mt-2" id="deploy-access" label="shell">{{ $accessLine }}</x-terminal>
                    </li>
                    <li>
                        <div class="ss-step-title">{{ __('deploy_sources.agent.step_restart') }}</div>
                        <x-terminal class="mt-2" id="deploy-restart" label="shell">{{ $restartLine }}</x-terminal>
                        <p class="m-0 mt-2 text-sm text-muted">{{ __('deploy_sources.agent.then') }}</p>
                    </li>
                </ol>
            </details>
        </section>

        {{-- GitHub: details for every deployment, or deployments itself. --}}
        <section class="ss-panel" aria-labelledby="github-title">
            <div class="ss-panel-head">
                <div class="flex items-start gap-3">
                    <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-github"/></svg></span>
                    <div class="min-w-0">
                        <h2 id="github-title" class="ss-panel-title">{{ __('deploy_sources.github.title') }}</h2>
                        <p class="ss-panel-sub max-w-3xl">{{ __('deploy_sources.github.text') }}</p>
                    </div>
                </div>
                @if ($isAdmin)
                    <button type="button" class="ss-btn ss-btn-primary" popovertarget="github-connect"><svg class="ico"><use href="#i-plus"/></svg> {{ __('deploy_sources.github.connect') }}</button>
                @endif
            </div>

            @if ($newSecret)
                @php $fresh = $connections->firstWhere('id', $newSecret['id']); @endphp
                @if ($fresh)
                    <div class="mb-4 grid gap-4">
                        <x-secret-token :token="$newSecret['secret']" id="github-secret" :title="__('deploy_sources.github.secret_title', ['repository' => $fresh->repository])" />
                        <div class="rounded-xl border border-line p-4">
                            <h3 class="m-0 text-sm font-semibold">{{ __('deploy_sources.github.setup_title') }}</h3>
                            <ol class="m-0 mt-3 grid gap-2 pl-5 text-sm text-muted">
                                <li>{{ __('deploy_sources.github.setup.open') }}</li>
                                <li>{{ __('deploy_sources.github.setup.url') }}:
                                    <x-terminal class="mt-1.5" id="github-url-new" :label="__('deploy_sources.github.webhook_url')">{{ $fresh->webhookUrl() }}</x-terminal>
                                </li>
                                <li>{{ __('deploy_sources.github.setup.type') }}</li>
                                <li>{{ __('deploy_sources.github.setup.secret') }}</li>
                                <li>{{ __('deploy_sources.github.setup.events') }}</li>
                            </ol>
                        </div>
                    </div>
                @endif
            @endif

            @if ($connections->isEmpty())
                <x-empty-state icon="i-github" :title="__('deploy_sources.github.empty')">{{ $isAdmin ? __('deploy_sources.github.application_hint') : __('deploy_sources.github.admin_only') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('deploy_sources.github.col_repository') }}</th><th scope="col">{{ __('deploy_sources.github.col_mode') }}</th><th scope="col">{{ __('deploy_sources.github.col_last') }}</th>@if ($isAdmin)<th scope="col"><span class="sr-only">{{ __('deploy_sources.github.disconnect') }}</span></th>@endif</tr></thead>
                        <tbody>
                            @foreach ($connections as $connection)
                                <tr>
                                    <td class="is-primary">
                                        <a class="ss-hit font-semibold text-fg no-underline hover:text-accent" href="https://github.com/{{ $connection->repository }}" target="_blank" rel="noopener">{{ $connection->repository }}</a>
                                        <span class="block text-xs text-muted">{{ $connection->application }} · {{ $connection->environment }}</span>
                                    </td>
                                    <td data-label="{{ __('deploy_sources.github.col_mode') }}">{{ __('deploy_sources.github.modes.'.$connection->deploy_on) }}@if ($connection->deploy_on === 'push')<span class="block text-xs text-muted">{{ $connection->branch ?: __('deploy_sources.github.branch_hint') }}</span>@endif</td>
                                    <td data-label="{{ __('deploy_sources.github.col_last') }}">
                                        @if ($connection->last_delivery_at)
                                            <x-status status="healthy" :label="Time::relative($connection->last_delivery_at)" />
                                            <span class="mt-1 block text-xs text-muted">{{ $connection->last_event }}</span>
                                        @else
                                            <x-status status="unknown" :label="__('deploy_sources.github.never')" />
                                        @endif
                                    </td>
                                    @if ($isAdmin)
                                        <td class="num" data-label="">
                                            <x-row-actions :label="$connection->repository" :links="[[__('deploy_sources.github.settings'), 'https://github.com/'.$connection->repository.'/settings/hooks', 'i-external']]">
                                                <button class="ss-menu-item" role="menuitem" type="button" data-copy-text="{{ $connection->webhookUrl() }}"><svg class="ico" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('deploy_sources.github.copy_url') }}</button>
                                                <form method="POST" action="{{ route('deploy-sources.github.renew', $connection) }}" data-confirm="{{ __('deploy_sources.github.renew_confirm') }}">@csrf<button type="submit" class="ss-menu-item" role="menuitem"><svg class="ico" aria-hidden="true"><use href="#i-key"/></svg>{{ __('deploy_sources.github.renew') }}</button></form>
                                                <form method="POST" action="{{ route('deploy-sources.github.destroy', $connection) }}" data-confirm="{{ __('deploy_sources.github.disconnect_confirm', ['repository' => $connection->repository]) }}">@csrf @method('DELETE')<button type="submit" class="ss-menu-item" role="menuitem"><svg class="ico" aria-hidden="true"><use href="#i-trash"/></svg>{{ __('deploy_sources.github.disconnect') }}</button></form>
                                            </x-row-actions>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($isAdmin)
        <div id="github-connect" popover class="ss-sheet ss-sheet-lg" role="dialog" aria-labelledby="github-connect-title" @if ($connectOpen) data-open @endif>
            <form method="POST" action="{{ route('deploy-sources.github.store') }}" class="grid gap-4 p-5">
                @csrf
                <div class="flex items-start gap-3">
                    <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-github"/></svg></span>
                    <div class="min-w-0">
                        <h2 id="github-connect-title" class="m-0 text-base font-semibold text-fg">{{ __('deploy_sources.github.connect') }}</h2>
                        <p class="m-0 mt-1 text-sm text-muted">{{ __('deploy_sources.github.text') }}</p>
                    </div>
                </div>

                <div>
                    <label class="ss-label mb-1.5" for="repository">{{ __('deploy_sources.github.repository') }}</label>
                    <input id="repository" name="repository" class="ss-input font-mono" required maxlength="200" value="{{ old('repository') }}" placeholder="acme/shop" autocomplete="off" spellcheck="false" autofocus aria-describedby="repository-hint">
                    <p id="repository-hint" class="m-0 mt-1 text-xs text-subtle">{{ __('deploy_sources.github.repository_hint') }}</p>
                    @error('repository')<p class="m-0 mt-1 text-xs text-bad" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="ss-label mb-1.5" for="application">{{ __('deploy_sources.github.application') }}</label>
                        <input id="application" name="application" class="ss-input" required maxlength="100" value="{{ old('application') }}" placeholder="shop" autocomplete="off" spellcheck="false" aria-describedby="application-hint" data-github-application>
                        @error('application')<p class="m-0 mt-1 text-xs text-bad" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="ss-label mb-1.5" for="environment">{{ __('deploy_sources.github.environment') }}</label>
                        <input id="environment" name="environment" class="ss-input" required maxlength="50" value="{{ old('environment', 'production') }}" autocomplete="off" spellcheck="false">
                    </div>
                    <p id="application-hint" class="m-0 text-xs text-subtle sm:col-span-2">{{ __('deploy_sources.github.application_hint') }}</p>
                </div>

                <fieldset class="m-0 grid gap-2 border-0 p-0">
                    <legend class="ss-label mb-1.5">{{ __('deploy_sources.github.deploy_on') }}</legend>
                    @foreach (\App\Models\GithubConnection::DEPLOY_ON as $mode)
                        <label class="ss-choice">
                            <input type="radio" name="deploy_on" value="{{ $mode }}" @checked(old('deploy_on', 'agent') === $mode) data-github-mode>
                            <span><strong>{{ __('deploy_sources.github.deploy_on_options.'.$mode.'.0') }}</strong><span>{{ __('deploy_sources.github.deploy_on_options.'.$mode.'.1') }}</span></span>
                        </label>
                    @endforeach
                </fieldset>

                <div data-github-branch>
                    <label class="ss-label mb-1.5" for="branch">{{ __('deploy_sources.github.branch') }}</label>
                    <input id="branch" name="branch" class="ss-input font-mono" maxlength="255" value="{{ old('branch') }}" placeholder="main" autocomplete="off" spellcheck="false">
                    <p class="m-0 mt-1 text-xs text-subtle">{{ __('deploy_sources.github.branch_hint') }}</p>
                </div>

                @if ($servers->isNotEmpty() || $domains->isNotEmpty())
                    <fieldset class="m-0 border-0 p-0">
                        <legend class="ss-label mb-1">{{ __('deploy_sources.github.resources') }}</legend>
                        <p class="m-0 mb-2 text-xs text-subtle">{{ __('deploy_sources.github.resources_hint') }}</p>
                        <div class="ss-checklist">
                            @foreach ([['server', $servers, __('deploy_sources.github.servers')], ['domain', $domains, __('deploy_sources.github.websites')]] as [$type, $items, $heading])
                                @if ($items->isNotEmpty())
                                    <p class="ss-checklist-head">{{ $heading }}</p>
                                    @foreach ($items as $item)
                                        <label><input type="checkbox" name="resources[]" value="{{ $type }}:{{ $item->id }}" @checked(in_array($type.':'.$item->id, old('resources', []), true))> <span class="truncate">{{ $type === 'domain' ? $item->displayName() : $item->name }}</span></label>
                                    @endforeach
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="ss-btn ss-btn-secondary" popovertarget="github-connect" popovertargetaction="hide">{{ __('common.cancel') }}</button>
                    <button type="submit" class="ss-btn ss-btn-primary">{{ __('deploy_sources.github.submit') }}</button>
                </div>
            </form>
        </div>
    @endif
</x-layouts.app>
