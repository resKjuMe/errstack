<?php

// The release list (app/Http/Controllers/ReleaseController,
// resources/js/shell/pages/releases).
return [

    'title' => 'Releases',
    'help' => 'The shipped versions of the selected projects. A release appears on '
        .'its own as soon as an event carries it, and can additionally be announced '
        .'through the API at deploy time. "New" counts the issues that were first '
        .'seen in this version — the number that tells you whether a deploy brought '
        .'something along. Next to it: health and adoption — how many sessions of this '
        .'version crash and how far it has rolled out.',

    'list' => [
        'empty' => 'No releases in the selected period.',
        'empty_hint' => 'As soon as an event carries a version, it appears here.',
        'count' => ':count releases',
        'first_event' => 'First seen: :value',
        'no_events' => 'No event yet',
        'released_at' => 'Shipped: :value',
        'artifacts' => ':count artifacts',
        'no_artifacts' => 'No source maps',
    ],

    'columns' => [
        'version' => 'Version',
        'new' => 'New issues',
        'resolved' => 'Of those resolved',
        'resolved_hint' => 'This many of the issues first seen in this version are '
            .'resolved by now. The number does not say whether they were fixed in '
            .'this version.',
        'crash_free' => 'Crash-free',
        'crash_free_hint' => 'The share of this version\'s sessions that did not crash '
            .'— within the selected period and environment. Errors and abnormal exits '
            .'do not count as a crash.',
        'adoption' => 'Adoption',
        'adoption_hint' => 'The share of the project\'s sessions in the selected period '
            .'that ran this version. Anyone who used two versions within the period '
            .'counts in both — the shares therefore do not necessarily add up to a '
            .'hundred per cent.',
        'last_event' => 'Last seen',
    ],

    'sort' => [
        'label' => 'Sorting',
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'new_issues' => 'Most new issues',
        'crash_free' => 'Worst crash-free rate',
        'adoption' => 'Highest adoption',
    ],

    // The numbers from the session data (R7), as they appear in the list and on
    // the detail page.
    'health' => [
        'title' => 'Health',
        'help' => 'How many sessions and people made it through this version. The '
            .'numbers come from the session reports of the SDK and apply to the '
            .'selected period and environment.',
        'crash_free_sessions' => 'Crash-free sessions',
        'crash_free_users' => 'Crash-free users',
        'adoption_sessions' => 'Adoption (sessions)',
        'adoption_users' => 'Adoption (users)',
        'sessions' => 'Sessions',
        'crashed_sessions' => 'Of those crashed',
        'errored_sessions' => 'Of those with errors',
        'abnormal_sessions' => 'Of those abnormal',
        'users' => 'People',
        'crashed_users' => 'Of those with a crash',
        'unknown_hint' => 'Without sessions in the selected period the rate cannot be '
            .'stated. "100 %" would wrongly stand for "all good" here.',
        'no_users' => 'This number needs a user id in the events. Without one it stays '
            .'empty — and not 100 %.',
        'empty' => 'No sessions arrived for this version in the selected period.',
        'empty_hint' => 'Sessions are reported separately by the SDK; without them there '
            .'is no crash-free rate. A release without sessions is not healthy, it is '
            .'unknown.',
    ],

    // The comparison against the previous version — the whole point of the numbers.
    'comparison' => [
        'help' => '"99.2 % crash-free" alone does not say whether the deploy went well. '
            .'The comparison uses the version one row further down in the list — same '
            .'period, same environment.',
        'none' => 'There is no older version to compare this one against.',
        'none_hint' => 'That is the case for the first release recorded for a project.',
        'no_data' => 'The previous version has no sessions in the selected period — '
            .'without them there is nothing to compare.',
        'up' => 'better than :version',
        'down' => 'worse than :version',
        'flat' => 'unchanged against :version',
        'points' => ':value percentage points',
        'link' => 'Open previous version :version',
    ],

    'adoption' => [
        'title' => 'Adoption over time',
        'help' => 'The share of this version among all sessions of the project. The '
            .'curve answers what a single number leaves open: is the rollout still '
            .'climbing, or has it stalled?',
        'label' => 'Adoption over time',
        'empty' => 'No sessions arrived for this version in the selected period.',
        'empty_project' => 'No sessions at all arrived for this project in the selected '
            .'period.',
        'gap_hint' => 'Sections without any sessions of the project break the line: '
            .'during a quiet night adoption did not drop to zero, it is unknown.',
    ],

    'issues' => [
        'title' => 'Issues',
        'new' => 'New in this version',
        'new_hint' => 'Issues that were seen for the first time with this deploy.',
        'resolved' => 'Resolved in this version',
        'resolved_hint' => 'Issues that were marked resolved when this version shipped.',
        'regressed' => 'Came back with this version',
        'regressed_hint' => 'Issues that had been resolved and appeared again in this '
            .'version.',
    ],

    'artifacts' => [
        'title' => 'Build artifacts',
        'help' => 'The uploaded bundles and source maps of this version. Without them '
            .'minified stack traces stay unreadable.',
        'count' => ':count artifacts',
        'truncated' => ':shown of :total artifacts are shown.',
        'empty' => 'No artifacts were uploaded for this version.',
        'empty_hint' => 'A build uploads them at the end of its run; '
            .'POST /api/0/organizations/{org}/projects/{project}/releases/{version}/files.',
        'debug_id' => 'Debug id',
        'debug_id_hint' => 'This artifact can be matched without a matching path.',
        'source_map' => 'Source map: :value',
    ],

    'unordered' => 'unordered',
    'unordered_hint' => 'This version string cannot be read as a number (a commit '
        .'hash, for instance). It is therefore listed after the numbered versions, '
        .'sorted by time.',

    'environment_partial' => 'The selected environment does not decide which releases '
        .'are listed here: a release is shipped as a whole and does not belong to one '
        .'environment. It does apply to health and adoption — sessions do belong to an '
        .'environment.',

    // The detail page of a release (R2): what is in it.
    'deploys' => [
        'title' => 'Deployments',
        'help' => 'When this version landed in which environment. The point in time '
            .'does not follow from any event — it comes from the deployment '
            .'pipeline through the API.',
        'empty' => 'No deployment was reported for this version.',
        'empty_hint' => 'A pipeline reports it at the end of its run; '
            .'POST /api/0/organizations/{org}/projects/{project}/releases/{version}/deploys.',
        'environment' => 'Environment',
        'at' => 'Point in time',
        'duration' => 'Duration',
        'link' => 'Open build run',
        'marker' => 'Deployed: :version to :environment (:at)',

        'notification' => [
            'title' => ':version was deployed to :environment',
            'body' => 'Your changes are out: :commits commits are part of this '
                .'deployment of :project.',
            'context_project' => 'Project',
            'context_environment' => 'Environment',
        ],
    ],

    'detail' => [
        'title' => 'Release :version',
        'help' => 'What went out with this release and how it turned out. Commits come '
            .'from a connected repository or are handed over through the API at deploy '
            .'time — a release that only came into being through events has none. '
            .'Health, adoption and the comparison against the previous version apply to '
            .'the selected period.',
        'commits' => 'Commits',
        'commit_count' => ':count commits',
        'truncated' => 'Showing :shown of :total commits.',
        'empty' => 'No commits were handed over for this release.',
        'empty_hint' => 'A build can hand them over at deploy time, even without a '
            .'GitHub or GitLab integration.',
        'files' => ':count files',
        'no_files' => 'No files given',
        'author_unknown' => 'Author unknown',
        'author_member_hint' => 'This address belongs to an account in this organization.',
        'new_issues' => 'Issues first seen in this release',
        'back' => 'Back to releases',
        'released_at' => 'Shipped: :value',
        'first_event' => 'First seen: :value',
        'last_event' => 'Last seen: :value',
        'ref' => 'Ref: :value',
    ],

];
