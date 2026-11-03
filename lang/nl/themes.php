<?php

// Thema's (Profiel → Weergave, commandopalet). Licht en donker zijn de
// standaard; de andere gelden alleen als je ze expliciet kiest.
return [
    'title' => 'Weergave',
    'text' => 'Licht en donker volgen je systeem, tenzij je zelf kiest. De andere thema\'s gelden alleen als je er hier een kiest. Opgeslagen in deze browser.',
    'noscript' => 'Een thema kiezen heeft JavaScript nodig.',
    'default' => 'Standaard',
    'extra' => 'Extra thema\'s',
    'names' => [
        'system' => 'Systeem',
        'light' => 'Licht',
        'dark' => 'Donker',
        'midnight' => 'Midnight',
        'terminal' => 'Terminal',
        'contrast' => 'Hoog contrast',
    ],
    'descriptions' => [
        'system' => 'Licht of donker, zoals je apparaat is ingesteld',
        'light' => 'Altijd licht',
        'dark' => 'Altijd donker',
        'midnight' => 'Diep blauw met een neongloed',
        'terminal' => 'Groen op zwart, monospace',
        'contrast' => 'Maximaal contrast, duidelijke randen',
    ],
    'palette' => 'Thema: :name',
];
