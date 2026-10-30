<?php

// Public status page (/status/{slug}). The Dutch state labels must stay
// identical to StatusPagePresenter::STATE_LABELS (LabelsTest).
return [
    'title_suffix' => 'Status',
    'headline' => [
        'operational' => 'All systems operational',
        'degraded' => 'Some systems are running slower',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'There is an outage',
        'maintenance' => 'Scheduled maintenance',
        'unknown' => 'Status unknown',
    ],
    'state' => [
        'operational' => 'Operational',
        'degraded' => 'Degraded performance',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'Major outage',
        'maintenance' => 'Maintenance',
        'unknown' => 'Unknown',
    ],
    'incident_status' => [
        'detected' => 'Detected',
        'identified' => 'Identified',
        'investigating' => 'Investigating',
        'resolved' => 'Resolved',
    ],
    'incident' => [
        'outage_at' => 'Outage at :component',
        'disruption_at' => 'Disruption at :component',
        'a_component' => 'a component',
    ],
    'active_incidents' => 'Ongoing incidents',
    'since' => 'Since :time',
    'components' => 'Components',
    'availability_per_day' => 'Availability per day',
    'no_data' => 'no data',
    'days_ago' => '90 days ago',
    'available' => ':percent available',
    'no_data_yet' => 'no data yet',
    'today' => 'today',
    'no_components' => 'No components have been published yet.',
    'resolved_recent' => 'Resolved (last 7 days)',
    'updated' => 'Updated :time',
    'refreshes' => 'refreshes every minute',
    'powered_by' => 'powered by ServerScope',
];
