<?php

// Profile. The page texts follow in PR 7; the flash messages and the language field are translated now.
return [
    'locale' => [
        'label' => 'Language',
        'help' => 'The language of ServerScope for you. You can also switch in the user menu.',
    ],

    'flash' => [
        'saved' => 'Profile saved.',
        'password_changed' => 'Password changed. Other sessions have been signed out.',
        'notifications_saved' => 'Notification preferences saved.',
        'test_throttled' => 'Too many test notifications; please try again in a few minutes.',
        'test_sent' => 'Test notification sent. Check the delivery status under Notifications.',
    ],

    'two_factor' => [
        'flash' => [
            'code_invalid_check_time' => 'The code is invalid. Check the time on your phone and try again.',
            'enabled' => 'Two-factor authentication is enabled.',
            'recovery_codes_regenerated' => 'New recovery codes generated; the old ones no longer work.',
            'disabled' => 'Two-factor authentication is disabled.',
        ],
    ],
];
