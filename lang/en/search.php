<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search language
    |--------------------------------------------------------------------------
    |
    | The messages point at the spot that went wrong and, where possible, say
    | what belongs there instead. A search language that answers every mismatch
    | with "invalid input" forces guessing — and the guessing only ends when the
    | field is empty again.
    |
    */

    'errors' => [
        'missing_field' => 'A field name is missing before the colon.',
        'unclosed_quote' => 'This quote is never closed.',
        'unopened_paren' => 'This bracket is never opened.',
        'unclosed_paren' => 'This bracket is never closed.',
        'empty_paren' => 'There is nothing inside the brackets.',
        'empty_term' => 'There is an empty search term here.',
        'unexpected' => '“:term” stands here without context.',
        'unexpected_end' => 'A term is missing after “:term”.',
        'too_deep' => 'Too many nested brackets.',
        'missing_value' => 'A value is missing after “:field:”.',
        'missing_comparison_value' => 'A value for :field is missing after “:comparator”.',
        'unknown_value' => ':field does not know “:value”. Allowed: :allowed.',
        'not_a_number' => ':field expects a number, not “:value”.',
        'not_a_date' => ':field expects a date such as 2026-03-01, 2026-03-01 14:30 '
            .'or a span such as -24h — not “:value”.',
        'relative_with_comparison' => 'A span such as -24h already states the direction; '
            .'a comparison in front of it does not work for :field.',
        'no_comparison' => ':field cannot be compared with “:comparator”.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    |
    | The half sentence shown behind a field name in the suggestion list. It
    | answers "and what goes in there?" without anyone having to open a manual.
    |
    */

    'fields' => [
        'is' => 'State: unresolved, resolved, ignored',
        'level' => 'Level: fatal, error, warning, info, debug',
        'priority' => 'Priority: high, medium, low',
        'timesseen' => 'Number of events, also as a comparison: >100',
        'usersseen' => 'Users affected, also as a comparison: >10',
        'firstseen' => 'First seen: 2026-03-01 or -24h',
        'lastseen' => 'Last seen: 2026-03-01 or -24h',
        'release' => 'Release it was first or last seen in',
        'firstrelease' => 'Release it was first seen in',
        'assigned' => 'Ownership (arrives with a later task)',
        'bookmarks' => 'Bookmarked issues (arrives with a later task)',
        'user' => [
            'email' => 'E-mail of an affected user (searches the events)',
            'id' => 'Id of an affected user (searches the events)',
            'username' => 'Username of an affected user (searches the events)',
            'ip' => 'IP address of an affected user (searches the events)',
        ],
    ],

];
