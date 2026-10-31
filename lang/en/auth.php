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

    'login' => [
        'title' => 'Log in',
        'heading' => 'Welcome back',
        'intro' => 'Log in to see your servers and monitoring data.',
        'remember' => 'Remember me',
        'submit' => 'Log in',
        'forgot' => 'Forgot your password?',
    ],

    'register' => [
        'title' => 'Create account',
        'intro' => 'You get your own team. Only members of your team see your servers and domains.',
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
        'intro' => 'Enter the 6-digit code from your authenticator app, or use a recovery code.',
        'code' => 'Verification code',
        'recovery_code' => 'or recovery code',
        'submit' => 'Verify',
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
