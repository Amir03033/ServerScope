{{-- How to let the agent follow log files (onboarding and the "Set up" panel). --}}
@php
    $prefix ??= 'logs';
    $envLine = 'SERVERSCOPE_LOG_FILES=nginx=/var/log/nginx/error.log,app=/var/www/myapp/storage/logs/laravel.log';
    $accessLine = "sudo usermod -aG adm serverscope\nsudo setfacl -m u:serverscope:x /var/www /var/www/myapp /var/www/myapp/storage /var/www/myapp/storage/logs\nsudo setfacl -m u:serverscope:r /var/www/myapp/storage/logs/laravel.log";
@endphp
<ol class="ss-steps">
    <li>
        <div class="ss-step-title">{{ __('logs.onboarding.step_env') }}</div>
        <x-terminal class="mt-2" :id="$prefix.'-env'" label="/opt/serverscope-agent/.env">{{ $envLine }}</x-terminal>
    </li>
    <li>
        <div class="ss-step-title">{{ __('logs.onboarding.step_access') }}</div>
        <x-terminal class="mt-2" :id="$prefix.'-access'" label="shell">{{ $accessLine }}</x-terminal>
    </li>
    <li>
        <div class="ss-step-title">{{ __('logs.onboarding.step_restart') }}</div>
        <x-terminal class="mt-2" :id="$prefix.'-restart'" label="shell">sudo systemctl restart serverscope-agent</x-terminal>
        <p class="m-0 mt-2 text-sm text-muted">{{ __('logs.onboarding.then') }}</p>
    </li>
</ol>
<p class="m-0 mt-4 flex items-center gap-2 text-xs text-subtle"><svg class="ico h-3.5 w-3.5"><use href="#i-lock"/></svg> {{ __('logs.onboarding.privacy') }}</p>
