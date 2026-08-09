<?php

// The three overview pages (D5): organization, project, team.
// resources/js/shell/pages/Dashboard.jsx, projects/Overview.jsx,
// teams/Overview.jsx and the shared panel building blocks under
// resources/js/shell/pages/overview/.
return [

    'panel' => [
        'loading' => 'Loading …',
        'empty' => 'Nothing happened here in this period.',
        'failed' => 'This panel could not be loaded.',
        'retry' => 'Try again',
        'unknown' => 'This panel does not exist.',
        'stale' => 'These numbers are from the previous request — the new one failed.',
        'truncated' => 'Only part of the projects is shown: the overview does not query more than that.',
        'all' => 'View all',
    ],

    'setup' => [
        'title' => 'Nothing received yet',
        'description' => 'These projects have not reported anything yet. They show no chart on purpose — an empty chart would tell you nothing.',
        'action' => 'Set up project',
        'pending' => 'Waiting for the first event',
    ],

    'organization' => [
        'title' => 'Overview',
        'description' => 'What is going on in :name — in the selected period.',
        'no_projects' => [
            'title' => 'No projects yet',
            'description' => 'An overview needs something that reports. Create a project and connect it.',
            'action' => 'Go to projects',
        ],
        'help' => [
            'panels' => 'Every panel fetches its own numbers — the page is there right away and fills in.',
            'filter' => 'The filter bar above applies to every panel. Its selection lives in the address bar — the link shows the recipient the same view.',
            'links' => 'Every number leads to the view it came from — with the same period.',
        ],
        'errors' => [
            'title' => 'Error history',
            'description' => 'Reported errors across the selected projects.',
            'metric' => 'Errors',
        ],
        'transactions' => [
            'title' => 'Transaction history',
            'description' => 'Measured requests across the selected projects. Response times live per project — across several, a percentile is not a number.',
            'metric' => 'Requests',
        ],
        'projects' => [
            'title' => 'Top projects',
            'description' => 'Where most errors occurred in the period.',
            'metric' => 'Errors',
        ],
        'alerts' => [
            'title' => 'Open alerts',
            'description' => 'Alerts that are currently not fine — regardless of the selected period.',
            'value' => 'Last measured',
            'empty' => 'No alert is firing.',
        ],
        'quota' => [
            'title' => 'Quota',
            'description' => 'This month’s usage against the limits of the organization.',
            'unlimited' => 'No limit',
            'of' => 'of :limit',
        ],
    ],

    'project' => [
        'title' => 'Overview: :name',
        'description' => 'State and open points of this project.',
        'settings' => 'Settings',
        'alerts' => 'Alert overview',
        'issues_link' => 'Issue list',
        'errors' => [
            'title' => 'Error history',
            'description' => 'Reported errors of this project.',
            'metric' => 'Errors',
        ],
        'issues_panel' => [
            'title' => 'Latest issues',
            'description' => 'Open entries that occurred in the period.',
        ],
        'issues' => [
            'times_seen' => 'Events',
            'users_seen' => 'Users affected',
        ],
        'releases' => [
            'title' => 'Release health',
            'description' => 'The most recent releases in the selected period.',
            'crash_free' => 'Crash free',
            'adoption' => 'Adoption',
        ],
        'ownership' => [
            'title' => 'Ownership',
            'description' => 'Who owns this project — regardless of the period.',
            'teams' => 'Teams',
            'rules' => 'Active rules',
            'empty' => 'No team is assigned to this project.',
        ],
    ],

    'team' => [
        'title' => 'Team: :name',
        'description' => 'What is waiting for this team.',
        'settings' => 'Manage team',
        'projects' => [
            'title' => 'Team projects',
            'description' => 'The projects of this team with their errors in the period.',
            'metric' => 'Errors',
            'empty' => 'No project is assigned to this team.',
        ],
        'review' => [
            'title' => 'Issues for review',
            'description' => 'New entries nobody has looked at yet.',
        ],
        'assignments' => [
            'title' => 'Assignments',
            'description' => 'Open issues assigned to the team or one of its members.',
        ],
        'issues' => [
            'times_seen' => 'Events',
        ],
    ],

];
