<?php

// Navigation, user menu and the labels of the application shell
// (app/Support/ShellData, resources/js/shell).
return [

    'guest' => 'Guest',
    'sign_in' => 'Sign in',
    'sign_out' => 'Sign out',
    'menu' => 'Menu',

    'sidebar' => [
        'collapse' => 'Collapse sidebar',
        'expand' => 'Expand sidebar',
    ],

    // Organization switcher at the top of the sidebar.
    'org' => [
        'label' => 'Organization',
        'switch' => 'Switch organization',
        'create' => 'Create organization',
        'none' => 'No organization',
    ],

    // Permanent anchors at the bottom of the sidebar.
    'footer' => [
        'settings' => 'Settings',
        'notifications' => 'Notifications',
    ],

    // Sub-navigation of the settings area (app/Support/SettingsNav).
    'settings' => [
        'groups' => [
            'organization' => 'Organization',
            'projects' => 'Projects',
            'privacy' => 'Privacy and ingestion',
            'notifications' => 'Notifications',
            'account' => 'Account',
        ],

        'links' => [
            'organization' => 'General',
            'organizations' => 'All organizations',
            'audit_log' => 'Audit log',
            'repositories' => 'Repositories',
            'integrations' => 'Integrations',
            'organization_quotas' => 'Quotas',
            'projects' => 'All projects',
            'project' => 'General',
            'project_setup' => 'Setup',
            'project_keys' => 'Keys and DSN',
            'project_ownership' => 'Ownership',
            'project_grouping' => 'Grouping',
            'project_alerts' => 'Metric alerts',
            'project_issue_alerts' => 'Issue alerts',
            'project_crons' => 'Cron monitors',
            'project_uptime' => 'Uptime monitors',
            'project_performance' => 'Performance detection',
            'project_quotas' => 'Quotas',
            'organization_privacy' => 'Organization privacy',
            'project_privacy' => 'Project privacy',
            'project_filters' => 'Inbound filters',
            'project_sampling' => 'Sampling',
            'project_spikes' => 'Spike protection',
            'notification_channels' => 'Organization channels',
            'notification_preferences' => 'My notifications',
            'project_digest' => 'Digests',
            'profile' => 'Profile',
            'api_tokens' => 'Access tokens',
        ],
    ],

    // Headings of the navigation groups in the sidebar.
    'groups' => [
        'monitor' => 'Monitor',
        'investigate' => 'Investigate',
        'ship' => 'Ship',
    ],

    'links' => [
        'dashboard' => 'Overview',
        'dashboards' => 'Dashboards',
        'issues' => 'Issues',
        'tags' => 'Tags',
        'feedback' => 'Feedback',
        'releases' => 'Releases',
        'discover' => 'Discover',
        'performance' => 'Performance',
        'performance_issues' => 'Performance issues',
        'web_vitals' => 'Web Vitals',
        'profiling' => 'Profiles',
        'replays' => 'Replays',
    ],

    'menu_items' => [
        'profile' => 'Profile',
        'operations' => 'Operations',
        'components' => 'Components',
    ],

    'theme' => [
        'light' => 'Light theme',
        'dark' => 'Dark theme',
        'system' => 'System theme',
    ],

];
