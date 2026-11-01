<?php

// Application shell: navigation, top bar, user menu, footer.
return [
    'main' => 'Hoofdnavigatie',
    'skip' => 'Naar de inhoud',
    'brand_caption' => 'Server- en domeinmonitoring',
    'open_menu' => 'Menu openen',
    'close_menu' => 'Menu sluiten',

    'groups' => [
        'monitoring' => 'Monitoring',
        'incidents' => 'Incidenten',
        'reporting' => 'Rapportage',
        'admin' => 'Beheer',
        'account' => 'Account',
    ],

    'items' => [
        'dashboard' => 'Dashboard',
        'servers' => 'Servers',
        'domains' => 'Domeinen',
        'dependencies' => 'Afhankelijkheden',
        'deployments' => 'Deployments',
        'incidents' => 'Incidenten',
        'alerts' => 'Alerts',
        'reports' => 'Rapporten',
        'alert_rules' => 'Alertregels',
        'status_pages' => 'Statuspagina\'s',
        'webhooks' => 'Webhooks',
        'users' => 'Gebruikers',
        'billing' => 'Abonnement en betaling',
        'audit_log' => 'Auditlog',
        'system_health' => 'Systeemstatus',
        'agent_releases' => 'Agentreleases',
        'owner_commands' => 'Beheercommando\'s',
        'notifications' => 'Meldingen',
        'profile' => 'Profiel',
    ],

    'system' => [
        'healthy' => 'Monitoring werkt',
        'warning' => 'Monitoring: aandacht nodig',
        'critical' => 'Monitoring verstoord',
        'unknown' => 'Monitoring status',
        'link_platform' => 'Scheduler, wachtrij en controles',
        'link_team' => 'Scheduler en controles',
        'agent_connection' => 'Agentverbinding via HTTPS',
    ],

    'team' => [
        'label' => 'Team',
        'current' => 'Team: :team',
        'switched' => 'Je werkt nu in team :team.',
    ],

    'documentation' => 'Documentatie',

    'search' => [
        'open' => 'Zoeken',
        'label' => 'Zoeken',
        'placeholder' => 'Zoek servers, domeinen, incidenten…',
        'hint' => 'Zoek in servers, domeinen, incidenten en meer',
        'all_results' => 'Alle resultaten voor ":q"',
        'no_results' => 'Geen resultaten voor ":q".',
        'error' => 'Zoeken is mislukt. Probeer het opnieuw.',
        'loading' => 'Zoeken…',
        'navigate' => 'kiezen',
        'select' => 'openen',
        'close' => 'sluiten',
    ],

    'theme' => [
        'label' => 'Thema',
        'system' => 'systeem',
        'light' => 'licht',
        'dark' => 'donker',
    ],

    'user' => [
        'menu' => 'Gebruikersmenu',
        'signed_in_as' => 'Ingelogd als :email',
        'profile' => 'Profiel',
        'language' => 'Taal',
        'logout' => 'Uitloggen',
    ],

    'flash' => [
        'check_input' => 'Controleer de ingevoerde gegevens.',
    ],

    'footer' => 'ServerScope · Infrastructure monitoring',
];
