<?php

// Shared Blade components.
return [
    'secret_token' => [
        'title' => 'Bewaar dit token nu',
        'once' => 'Dit token wordt maar één keer getoond en is daarna niet meer op te vragen. Bewaar het veilig en deel het niet.',
    ],
    'line_chart' => [
        'no_data' => 'Nog geen meetgegevens in deze periode.',
        'failed_checks' => 'mislukte controles',
    ],
    'dependencies' => [
        'title' => 'Afhankelijkheden',
        'text' => 'Bevestigde relaties worden gebruikt om incidenten te groeperen; voorgestelde relaties niet.',
        'manage' => 'Beheren',
        'depends_on' => 'Hangt af van',
        'none_confirmed' => 'Geen bevestigde afhankelijkheden',
        'affected' => 'Mogelijk getroffen bij storing',
        'nothing_known' => 'Niets bekend',
        'pages' => ':count gemonitorde pagina|:count gemonitorde pagina\'s',
        'total' => 'In totaal :count resources, inclusief indirecte afhankelijkheden.',
        'suggested' => 'Voorgestelde relaties (niet bevestigd)',
        'confirm' => 'Bevestigen',
    ],
];
