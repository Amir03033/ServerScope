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

    'showcase' => [
        'title' => 'Weet het eerder dan je klanten.',
        'text' => 'ServerScope kijkt elke minuut naar je servers, websites en geplande taken, vindt de waarschijnlijke oorzaak en brengt je team meteen op de hoogte.',
        'points' => ['Live bewaking van servers, websites, cron-taken en logs', 'Oorzaakanalyse met een AI-interpretatie', 'Meldingen via e-mail, push, Slack, Teams, Telegram en Discord'],
        'cards' => [
            'online' => 'online',
            'ssl' => 'SSL geldig',
            'cause' => 'Waarschijnlijke oorzaak gevonden',
            'cause_text' => 'Deploy 2.14.0, 2 min eerder',
            'resolved' => 'Incident opgelost',
            'resolved_text' => 'in 4 minuten',
        ],
    ],
    'password_show' => 'Wachtwoord tonen',
    'password_hide' => 'Wachtwoord verbergen',
    'strength' => ['label' => 'Sterkte van het wachtwoord', 'weak' => 'Zwak', 'fair' => 'Redelijk', 'good' => 'Goed', 'strong' => 'Sterk'],
    'ghost' => 'Je dashboard wordt klaargezet…',
    'login' => [
        'title' => 'Inloggen',
        'heading' => 'Welkom terug',
        'intro' => 'Log in en zie meteen hoe je servers, websites en taken ervoor staan.',
        'remember' => 'Onthoud mij',
        'submit' => 'Inloggen',
        'forgot' => 'Wachtwoord vergeten?',
        'or' => 'of',
        'demo' => 'Bekijk de live demo',
        'demo_text' => 'Klik rond in een echte omgeving, zonder account.',
        'new_here' => 'Nieuw bij ServerScope?',
        'create' => 'Account aanmaken',
    ],

    'register' => [
        'title' => 'Account aanmaken',
        'intro' => 'In twee minuten klaar: account aanmaken, de agent met één commando installeren, en ServerScope houdt vanaf dan de wacht.',
        'submit' => 'Account aanmaken',
        'perks' => ['Een eigen team: alleen jouw leden zien je gegevens', 'De agent installeer je met één commando', 'Meldingen waar jij ze wilt: mail, push of chat'],
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
        'heading' => 'Nog één stap',
        'intro' => 'Open je authenticator-app en vul de 6-cijferige code voor ServerScope in.',
        'code' => 'Verificatiecode',
        'digit' => 'Cijfer :n van 6',
        'refresh' => 'Nieuwe code over :seconds s',
        'recovery_toggle' => 'Geen toegang tot je app? Gebruik een herstelcode',
        'recovery_code' => 'Herstelcode',
        'submit' => 'Verifiëren',
        'secure' => 'Je account is beveiligd met tweestapsverificatie',
        'back' => 'Terug naar inloggen',
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
