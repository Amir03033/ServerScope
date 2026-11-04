<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'footer' => 'ServerScope · Secure server monitoring',

    'fields' => [
        'name' => 'Name',
        'team' => 'Company or team name',
        'email' => 'Email address',
        'password' => 'Password',
        'new_password' => 'New password',
        'password_confirmation' => 'Repeat password',
    ],

    'showcase' => [
        'title' => 'Know before your customers do.',
        'text' => 'ServerScope watches your servers, websites and scheduled jobs every minute, finds the likely cause and tells your team right away.',
        'points' => ['Live monitoring of servers, websites, cron jobs and logs', 'Root cause analysis with an AI interpretation', 'Alerts by e-mail, push, Slack, Teams, Telegram and Discord'],
        'cards' => [
            'online' => 'online',
            'ssl' => 'SSL valid',
            'cause' => 'Likely cause found',
            'cause_text' => 'Deploy 2.14.0, 2 min before',
            'resolved' => 'Incident resolved',
            'resolved_text' => 'in 4 minutes',
        ],
    ],
    'password_show' => 'Show password',
    'password_hide' => 'Hide password',
    'strength' => ['label' => 'Password strength', 'weak' => 'Weak', 'fair' => 'Fair', 'good' => 'Good', 'strong' => 'Strong'],
    'ghost' => 'Getting your dashboard ready…',
    'login' => [
        'title' => 'Log in',
        'heading' => 'Welcome back',
        'intro' => 'Log in and see right away how your servers, websites and jobs are doing.',
        'remember' => 'Remember me',
        'submit' => 'Log in',
        'forgot' => 'Forgot your password?',
        'or' => 'or',
        'demo' => 'Explore the live demo',
        'demo_text' => 'Click around a real setup, no account needed.',
        'new_here' => 'New to ServerScope?',
        'create' => 'Create an account',
    ],

    'register' => [
        'title' => 'Create account',
        'intro' => 'Ready in two minutes: create your account, install the agent with one command, and ServerScope keeps watch from then on.',
        'submit' => 'Create account',
        'perks' => ['Your own team: only your members see your data', 'Install the agent with a single command', 'Alerts where you want them: email, push or chat'],
        'sent_title' => 'Check your email',
        'sent_body' => 'If this address can be used, we have sent you an email. Follow the link in it to continue. Nothing is available until the address is verified.',
        'have_account' => 'I already have an account',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'intro' => 'Enter your email address. If it belongs to an account, you will receive a link to set a new password.',
        'submit' => 'Send reset link',
        'back' => 'Back to log in',
    ],

    'reset' => [
        'title' => 'New password',
        'intro' => 'At least 12 characters with uppercase letters, lowercase letters and numbers.',
        'submit' => 'Save password',
    ],

    'two_factor' => [
        'title' => 'Two-factor authentication',
        'heading' => 'One more step',
        'intro' => 'Open your authenticator app and enter the 6-digit code for ServerScope.',
        'code' => 'Verification code',
        'digit' => 'Digit :n of 6',
        'refresh' => 'New code in :seconds s',
        'recovery_toggle' => 'No access to your app? Use a recovery code',
        'recovery_code' => 'Recovery code',
        'submit' => 'Verify',
        'secure' => 'Your account is protected with two-factor authentication',
        'back' => 'Back to log in',
    ],

    'messages' => [
        'throttled' => 'Too many login attempts. Please try again later.',
        'invalid_credentials' => 'These credentials are incorrect.',
        'reset_link_sent' => 'If this email address belongs to an account, a reset link has been sent. The link is valid for 60 minutes.',
        'password_reset' => 'Your password has been reset. You can now log in.',
        'reset_link_invalid' => 'This reset link is invalid or has expired.',
        'two_factor_throttled' => 'Too many attempts. Please try again in :seconds seconds.',
        'two_factor_invalid' => 'The code is invalid.',
    ],

    'verify' => [
        'title' => 'Verify your email address',
        'intro' => 'We sent a link to :email. Open it to use your account.',
        'resend' => 'Send the email again',
        'logout' => 'Sign out',
        'done' => 'Your email address is verified. You can now sign in.',
        'sent' => 'A new link has been sent.',
    ],

    'mail' => [
        'verify' => ['subject' => 'Verify your email address', 'line1' => 'Click the button below to confirm this email address for your ServerScope account.', 'action' => 'Verify email address', 'line2' => 'If you did not create an account, you can ignore this email.'],
        'attempt' => ['subject' => 'Someone tried to register with your address', 'line1' => 'Someone tried to create a ServerScope account with this email address, but you already have an account.', 'action' => 'Reset my password', 'line2' => 'If this was not you, you can ignore this email.'],
    ],

    'terms' => [
        'label' => 'I accept the :terms and the :privacy.',
        'terms' => 'terms and conditions',
        'privacy' => 'privacy statement',
    ],
];
