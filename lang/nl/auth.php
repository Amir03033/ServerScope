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
        'sent_title' => 'Controleer je e-mail',
        'sent_body' => 'Als dit adres gebruikt kan worden, hebben we je een e-mail gestuurd. Volg de link daarin om verder te gaan. Er is niets beschikbaar zolang het adres niet is bevestigd.',
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

    'verify' => [
        'title' => 'Bevestig je e-mailadres',
        'intro' => 'We hebben een link gestuurd naar :email. Open die om je account te gebruiken.',
        'resend' => 'Stuur de e-mail opnieuw',
        'logout' => 'Uitloggen',
        'done' => 'Je e-mailadres is bevestigd. Je kunt nu inloggen.',
        'sent' => 'Er is een nieuwe link verstuurd.',
    ],

    'mail' => [
        'verify' => ['subject' => 'Bevestig je e-mailadres', 'line1' => 'Klik op de knop hieronder om dit e-mailadres voor je ServerScope-account te bevestigen.', 'action' => 'E-mailadres bevestigen', 'line2' => 'Heb je geen account aangemaakt? Dan kun je deze e-mail negeren.'],
        'attempt' => ['subject' => 'Iemand probeerde te registreren met je adres', 'line1' => 'Iemand probeerde een ServerScope-account aan te maken met dit e-mailadres, maar je hebt al een account.', 'action' => 'Wachtwoord opnieuw instellen', 'line2' => 'Was jij dit niet? Dan kun je deze e-mail negeren.'],
    ],

    'terms' => [
        'label' => 'Ik ga akkoord met de :terms en de :privacy.',
        'terms' => 'algemene voorwaarden',
        'privacy' => 'privacyverklaring',
    ],
];
