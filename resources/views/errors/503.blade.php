@php
    // Laravel maintenance mode (php artisan down --retry=N) sends Retry-After;
    // only then is a return estimate shown. Nothing here needs the database.
    $retry = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? null) : null;
    $minutes = is_numeric($retry) && (int) $retry > 0 ? max(1, (int) ceil((int) $retry / 60)) : null;
@endphp
@include('errors.layout', [
    'code' => 503, 'tone' => '#fbbf24',
    'signal' => __('errors.503.signal'), 'title' => __('errors.503.title'), 'text' => __('errors.503.text'),
    'extra' => $minutes ? trans_choice('errors.503.retry', $minutes, ['minutes' => $minutes]) : __('errors.503.no_estimate'),
    'actions' => ['retry', 'status'],
])
