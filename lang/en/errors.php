<?php

return [
    'actions' => [
        'dashboard' => 'Return to dashboard',
        'home' => 'Go to the homepage',
        'back' => 'Go back',
        'retry' => 'Try again',
        'status' => 'View status page',
        'login' => 'Sign in again',
    ],
    '403' => [
        'signal' => 'Access denied',
        'title' => 'You do not have access to this page',
        'text' => 'Your account does not have permission for this action. Ask an owner or administrator of your team if you need access.',
    ],
    '404' => [
        'signal' => 'Signal not found',
        'title' => '404 — Signal not found',
        'text' => 'The page you’re looking for doesn’t exist or may have moved.',
    ],
    '419' => [
        'signal' => 'Session expired',
        'title' => 'This page has expired',
        'text' => 'For your security the form expired. Reload the page and try again; your changes were not saved.',
    ],
    '429' => [
        'signal' => 'Rate limited',
        'title' => 'Too many requests',
        'text' => 'You sent many requests in a short time. Wait a moment and try again.',
    ],
    '500' => [
        'signal' => 'Internal error',
        'title' => 'Something went wrong on our side',
        'text' => 'The error has been logged. Try again in a moment; if it keeps happening, contact support.',
    ],
    '503' => [
        'signal' => 'Maintenance',
        'title' => 'ServerScope is undergoing maintenance',
        'text' => 'We’re performing scheduled maintenance to improve reliability. Please try again shortly.',
        'no_estimate' => 'No return time has been announced yet.',
        'retry' => 'Expected back in about a minute.|Expected back in about :minutes minutes.',
    ],
];
