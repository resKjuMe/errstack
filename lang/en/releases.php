<?php

// The release list (app/Http/Controllers/ReleaseController,
// resources/js/shell/pages/releases).
return [

    'title' => 'Releases',
    'help' => 'The shipped versions of the selected projects. A release appears on '
        .'its own as soon as an event carries it, and can additionally be announced '
        .'through the API at deploy time. "New" counts the issues that were first '
        .'seen in this version — the number that tells you whether a deploy brought '
        .'something along.',

    'list' => [
        'empty' => 'No releases in the selected period.',
        'empty_hint' => 'As soon as an event carries a version, it appears here.',
        'count' => ':count releases',
        'first_event' => 'First seen: :value',
        'no_events' => 'No event yet',
        'released_at' => 'Shipped: :value',
    ],

    'columns' => [
        'version' => 'Version',
        'new' => 'New issues',
        'resolved' => 'Of those resolved',
        'resolved_hint' => 'This many of the issues first seen in this version are '
            .'resolved by now. The number does not say whether they were fixed in '
            .'this version.',
        'last_event' => 'Last seen',
    ],

    'unordered' => 'unordered',
    'unordered_hint' => 'This version string cannot be read as a number (a commit '
        .'hash, for instance). It is therefore listed after the numbered versions, '
        .'sorted by time.',

    'environment_ignored' => 'The selected environment has no effect here: a release is '
        .'shipped as a whole and does not belong to one environment.',

    // The detail page of a release (R2): what is in it.
    'detail' => [
        'title' => 'Release :version',
        'help' => 'What went out with this release. Commits come from a connected '
            .'repository or are handed over through the API at deploy time — a release '
            .'that only came into being through events has none.',
        'commits' => 'Commits',
        'commit_count' => ':count commits',
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
