<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    */

    'failed' => 'Deze inloggegevens zijn bij ons niet bekend.',
    'password' => 'Het opgegeven wachtwoord is onjuist.',
    'throttle' => 'Te veel inlogpogingen. Probeer het over :seconds seconden opnieuw.',

    'footer' => 'ServerScope · Secure server monitoring',

    'fields' => [
        'name' => 'Naam',
        'team' => 'Bedrijf of teamnaam',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'new_password' => 'Nieuw wachtwoord',
        'password_confirmation' => 'Herhaal wachtwoord',
    ],

    'login' => [
        'title' => 'Inloggen',
        'heading' => 'Welkom terug',
        'intro' => 'Log in om je servers en monitoringgegevens te bekijken.',
        'remember' => 'Onthoud mij',
        'submit' => 'Inloggen',
        'forgot' => 'Wachtwoord vergeten?',
    ],

    'register' => [
        'title' => 'Account aanmaken',
        'intro' => 'Je krijgt een eigen team. Alleen leden van je team zien je servers en domeinen.',
        'have_account' => 'Ik heb al een account',
    ],

    'forgot' => [
        'title' => 'Wachtwoord vergeten',
        'intro' => 'Vul je e-mailadres in. Als er een account bij hoort, ontvang je een link om een nieuw wachtwoord in te stellen.',
        'submit' => 'Resetlink versturen',
        'back' => 'Terug naar inloggen',
    ],

    'reset' => [
        'title' => 'Nieuw wachtwoord',
        'intro' => 'Minimaal 12 tekens met hoofdletters, kleine letters en cijfers.',
        'submit' => 'Wachtwoord opslaan',
    ],

    'two_factor' => [
        'title' => 'Tweestapsverificatie',
        'intro' => 'Voer de 6-cijferige code uit je authenticator-app in, of gebruik een herstelcode.',
        'code' => 'Verificatiecode',
        'recovery_code' => 'of herstelcode',
        'submit' => 'Verifiëren',
    ],

    'messages' => [
        'throttled' => 'Te veel inlogpogingen. Probeer het later opnieuw.',
        'invalid_credentials' => 'De opgegeven inloggegevens zijn onjuist.',
        'reset_link_sent' => 'Als dit e-mailadres bij een account hoort, is er een resetlink verstuurd. De link is 60 minuten geldig.',
        'password_reset' => 'Je wachtwoord is opnieuw ingesteld. Je kunt nu inloggen.',
        'reset_link_invalid' => 'Deze resetlink is ongeldig of verlopen.',
        'two_factor_throttled' => 'Te veel pogingen. Probeer het over :seconds seconden opnieuw.',
        'two_factor_invalid' => 'De code is ongeldig.',
    ],

];
