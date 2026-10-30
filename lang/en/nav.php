<?php

// Application shell: navigation, top bar, user menu, footer.
return [
    'main' => 'Main navigation',
    'skip' => 'Skip to content',
    'brand_caption' => 'Server and domain monitoring',
    'open_menu' => 'Open menu',
    'close_menu' => 'Close menu',

    'groups' => [
        'monitoring' => 'Monitoring',
        'incidents' => 'Incidents',
        'reporting' => 'Reporting',
        'admin' => 'Administration',
        'account' => 'Account',
    ],

    'items' => [
        'dashboard' => 'Dashboard',
        'servers' => 'Servers',
        'domains' => 'Domains',
        'dependencies' => 'Dependencies',
        'incidents' => 'Incidents',
        'alerts' => 'Alerts',
        'reports' => 'Reports',
        'alert_rules' => 'Alert rules',
        'status_pages' => 'Status pages',
        'webhooks' => 'Webhooks',
        'users' => 'Users',
        'audit_log' => 'Audit log',
        'system_health' => 'System status',
        'agent_releases' => 'Agent releases',
        'notifications' => 'Notifications',
        'profile' => 'Profile',
    ],

    'system' => [
        'healthy' => 'Monitoring is running',
        'warning' => 'Monitoring: needs attention',
        'critical' => 'Monitoring disrupted',
        'unknown' => 'Monitoring status',
        'link_platform' => 'Scheduler, queue and checks',
        'link_team' => 'Scheduler and checks',
        'agent_connection' => 'Agent connection over HTTPS',
    ],

    'team' => [
        'label' => 'Team',
        'current' => 'Team: :team',
        'switched' => 'You are now working in team :team.',
    ],

    'documentation' => 'Documentation',

    'search' => [
        'open' => 'Search',
        'label' => 'Search',
        'placeholder' => 'Search servers, domains, incidents…',
        'hint' => 'Search servers, domains, incidents and more',
        'all_results' => 'All results for ":q"',
        'no_results' => 'No results for ":q".',
        'loading' => 'Searching…',
        'navigate' => 'choose',
        'select' => 'open',
        'close' => 'close',
    ],

    'theme' => [
        'label' => 'Theme',
        'system' => 'system',
        'light' => 'light',
        'dark' => 'dark',
    ],

    'user' => [
        'menu' => 'User menu',
        'signed_in_as' => 'Signed in as :email',
        'profile' => 'Profile',
        'language' => 'Language',
        'logout' => 'Log out',
    ],

    'flash' => [
        'check_input' => 'Please check the information you entered.',
    ],

    'footer' => 'ServerScope · Infrastructure monitoring',
];
