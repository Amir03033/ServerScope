<?php

return [
    'title' => 'Skyline',
    'subtitle' => 'Your servers as a city at night: busier servers stand taller, lit windows are running services.',
    'back' => 'NEXUS map',
    'map_label' => 'Skyline',
    'canvas_label' => 'A city of your servers; the list next to it shows the same information.',
    'loading' => 'Building the city…',
    'noscript' => 'The skyline needs JavaScript; your servers are listed on the Servers page.',
    'list_title' => 'Servers',
    'list_text' => 'Choose a server to fly to its tower.',
    'empty' => 'This team has no servers yet.',
    'legend' => [
        'height_term' => 'Height',
        'height' => 'CPU load (low when not measured)',
        'windows_term' => 'Windows',
        'windows' => 'Services: lit when running, red when failing, dark when paused',
        'beacons_term' => 'Roof lights',
        'beacons' => 'Websites on the server: green reachable, red down',
        'dark_term' => 'Lights out',
        'dark' => 'The server does not report; flickering: a failure on it',
    ],
    'js' => [
        'lights' => [
            'on' => 'Lights on',
            'flicker' => 'Flickering',
            'out' => 'Lights out',
            'dim' => 'Dimmed',
        ],
        'row' => 'CPU :cpu · :sites websites · :down down',
        'open' => 'Open server',
        'no_webgl' => 'This browser or device does not support WebGL, so the city is not drawn; the list shows the same information.',
    ],
];
