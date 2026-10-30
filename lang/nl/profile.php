<?php

// Profile. The page texts follow in PR 7; the flash messages and the language field are translated now.
return [
    'locale' => [
        'label' => 'Taal',
        'help' => 'De taal van ServerScope voor jou. Je kunt ook wisselen in het gebruikersmenu.',
    ],

    'flash' => [
        'saved' => 'Profiel opgeslagen.',
        'password_changed' => 'Wachtwoord gewijzigd. Andere sessies zijn afgemeld.',
        'notifications_saved' => 'Meldingsvoorkeuren opgeslagen.',
        'test_throttled' => 'Te veel testmeldingen; probeer het over een paar minuten opnieuw.',
        'test_sent' => 'Testmelding verstuurd. Bekijk de verzendstatus onder Meldingen.',
    ],

    'two_factor' => [
        'flash' => [
            'code_invalid_check_time' => 'De code is ongeldig. Controleer de tijd op je telefoon en probeer het opnieuw.',
            'enabled' => 'Tweestapsverificatie is ingeschakeld.',
            'recovery_codes_regenerated' => 'Nieuwe herstelcodes gegenereerd; de oude zijn ongeldig.',
            'disabled' => 'Tweestapsverificatie is uitgeschakeld.',
        ],
    ],
];
